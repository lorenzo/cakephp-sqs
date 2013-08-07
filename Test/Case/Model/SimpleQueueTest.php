<?php

use Aws\Sqs\SqsClient;

App::uses('SimpleQueue', 'SQS.Model');

class SQSTestException extends Exception {

	protected $_exceptionCode;

	public function setExceptionCode($code) {
		$this->_exceptionCode = $code;
	}

	public function getExceptionCode() {
		return $this->_exceptionCode;
	}

}

/**
 * Tests SimpleQueue class
 *
 **/
class SimpleQueueTest extends CakeTestCase {

/**
 * Sets up a mocked logger stream
 *
 * @return void
 **/
	public function setUp() {
		parent::setUp();

		$class = $this->getMockClass('BaseLog', array('write'), array(), 'SQSBaseLog');
		CakeLog::config('queuetest', array(
			'engine' => $class,
			'types' => array('error', 'debug'),
			'scopes' => array('sqs')
		));
		$this->logger = CakeLog::stream('queuetest');
		CakeLog::disable('stderr');
		Configure::write('SQS', array());
	}

/**
 * Restores everything back to normal
 *
 * @return void
 **/
	public function tearDown() {
		parent::tearDown();
		CakeLog::enable('stderr');
		CakeLog::drop('queuetest');
		Configure::write('SQS', array());
		unset($this->logger);
	}

/**
 * Tests client method
 *
 * @return void
 */
	public function testClient() {
		Configure::write('SQS', array('connection' => array(
			'key' => 'a',
			'secret' => 'b',
			'region' => 'us-east-1'
		)));
		$queue = new SimpleQueue;
		$client = $queue->client();
		$this->assertInstanceOf('\Aws\Sqs\SqsClient', $client);
		$this->assertSame($client, $queue->client());

		$queue->client(false);
		$client2 = $queue->client();
		$this->assertInstanceOf('\Aws\Sqs\SqsClient', $client2);
		$this->assertNotSame($client, $client2);

		$client3 = SqsClient::factory(Configure::read('SQS.connection'));
		$queue->client($client3);
		$this->assertSame($client3, $queue->client());
	}

/**
 * Tests send method
 *
 * @return void
 */
	public function testSendMessage() {
		Configure::write('SQS', array(
			'connection' => array(
				'key' => 'a',
				'secret' => 'b',
				'region' => 'us-east-1'
			),
			'queues' => array(
				'foo' => 'http://fake.local'
			)
		));
		$client = $this->getMock('\Aws\Sqs\SqsClient', array('sendMessage'), array(), '', false);
		$queue = new SimpleQueue;
		$queue->client($client);

		$this->logger->expects($this->once())
			->method('write')
			->with('debug', 'Creating background job: foo');

		$model = $this->getMock('\Guzzle\Service\Resource\Model');
		$model->expects($this->once())->method('get')
			->with('MessageId')
			->will($this->returnValue('bar'));

		$client->expects($this->once())->method('sendMessage')
			->with(array(
				'QueueUrl' => 'http://fake.local',
				'MessageBody' => json_encode(array('my' => 'data'))
			))
			->will($this->returnValue($model));

		$this->assertTrue($queue->send('foo', array('my' => 'data')));
	}

/**
 * Tests send method bad return
 *
 * @return void
 */
	public function testSendMessageBadReturn() {
		Configure::write('SQS', array(
			'connection' => array(
				'key' => 'a',
				'secret' => 'b',
				'region' => 'us-east-1'
			),
			'queues' => array(
				'foo' => 'http://fake.local'
			)
		));
		$client = $this->getMock('\Aws\Sqs\SqsClient', array('sendMessage'), array(), '', false);
		$queue = new SimpleQueue;
		$queue->client($client);

		$this->logger->expects($this->at(0))
			->method('write')
			->with('debug', 'Creating background job: foo');

		$this->logger->expects($this->at(1))
			->method('write')
			->with('error', 'Could not create background job for task foo');

		$model = $this->getMock('\Guzzle\Service\Resource\Model');
		$model->expects($this->once())->method('get')
			->with('MessageId')
			->will($this->returnValue(null));

		$client->expects($this->once())->method('sendMessage')
			->with(array(
				'QueueUrl' => 'http://fake.local',
				'MessageBody' => json_encode(array('my' => 'data'))
			))
			->will($this->returnValue($model));

		$this->assertFalse($queue->send('foo', array('my' => 'data')));
	}

/**
 * Tests send method exception
 *
 * @return void
 */
	public function testSendMessageException() {
		Configure::write('SQS', array(
			'connection' => array(
				'key' => 'a',
				'secret' => 'b',
				'region' => 'us-east-1'
			),
			'queues' => array(
				'foo' => 'http://fake.local'
			)
		));
		$client = $this->getMock('\Aws\Sqs\SqsClient', array('sendMessage'), array(), '', false);
		$queue = new SimpleQueue;
		$queue->client($client);

		$this->logger->expects($this->at(0))
			->method('write')
			->with('debug', 'Creating background job: foo');

		$this->logger->expects($this->at(1))
			->method('write')
			->with('error', 'you fail');

		$model = $this->getMock('\Guzzle\Service\Resource\Model');
		$model->expects($this->never())->method('get');
		$client->expects($this->once())->method('sendMessage')
			->with(array(
				'QueueUrl' => 'http://fake.local',
				'MessageBody' => json_encode(array('my' => 'data'))
			))
			->will($this->throwException(new SQSTestException('you fail')));

		$this->assertFalse($queue->send('foo', array('my' => 'data')));
	}

/**
 * Tests send method with missing config for queue
 *
 * @expectedException InvalidArgumentException
 * @expectedExceptionMessage foo URL was not configured. Use Configure::write('SQS.queues.foo', '$url')
 * @return void
 */
	public function testSendMissingConfig() {
		Configure::write('SQS', array(
			'connection' => array(
				'key' => 'a',
				'secret' => 'b',
				'region' => 'us-east-1'
			),
			'queues' => array(
				'wuut' => 'http://fake.local'
			)
		));
		$queue = new SimpleQueue;
		$queue->send('foo', array('my' => 'data'));
	}

/**
 * Tests sendBatch method
 *
 * @return void
 */
	public function testSendBatch() {
		Configure::write('SQS', array(
			'connection' => array(
				'key' => 'a',
				'secret' => 'b',
				'region' => 'us-east-1'
			),
			'queues' => array(
				'foo' => 'http://fake.local'
			)
		));
		$client = $this->getMock('\Aws\Sqs\SqsClient', array('sendMessageBatch'), array(), '', false);
		$queue = new SimpleQueue;
		$queue->client($client);

		$this->logger->expects($this->at(0))
			->method('write')
			->with('debug', 'Creating 2 messages in queue: foo');

		$model = $this->getMock('\Guzzle\Service\Resource\Model');
		$model->expects($this->once())->method('get')
			->with('Failed')
			->will($this->returnValue(null));

		$client->expects($this->once())->method('sendMessageBatch')
			->with(array(
				'QueueUrl' => 'http://fake.local',
				'Entries' => array(
					array('Id' => 'a', 'MessageBody' => json_encode(array(1))),
					array('Id' => 'a1', 'MessageBody' => json_encode(array(2))),
				)
			))
			->will($this->returnValue($model));

		$data = array(
			array(1),
			array(2)
		);
		$this->assertEquals(array(), $queue->sendBatch('foo', $data));
	}

/**
 * Tests sendBatch with failures
 *
 * @return void
 */
	public function testSendBatchFailures() {
		Configure::write('SQS', array(
			'connection' => array(
				'key' => 'a',
				'secret' => 'b',
				'region' => 'us-east-1'
			),
			'queues' => array(
				'foo' => 'http://fake.local'
			)
		));
		$client = $this->getMock('\Aws\Sqs\SqsClient', array('sendMessageBatch'), array(), '', false);
		$queue = new SimpleQueue;
		$queue->client($client);

		$model = $this->getMock('\Guzzle\Service\Resource\Model');
		$model->expects($this->once())->method('get')
			->with('Failed')
			->will($this->returnValue(array(array('Id' => 'a1', 'Message' => 'you fail'))));

		$client->expects($this->once())->method('sendMessageBatch')
			->with(array(
				'QueueUrl' => 'http://fake.local',
				'Entries' => array(
					array('Id' => 'a', 'MessageBody' => json_encode(array(1))),
					array('Id' => 'a1', 'MessageBody' => json_encode(array(2))),
				)
			))
			->will($this->returnValue($model));

		$data = array(
			array(1),
			array(2)
		);
		$this->assertEquals(array('1' => 'you fail'), $queue->sendBatch('foo', $data));
	}

/**
 * Tests sendBatch with exception
 *
 * @return void
 */
	public function testSendBatchException() {
		Configure::write('SQS', array(
			'connection' => array(
				'key' => 'a',
				'secret' => 'b',
				'region' => 'us-east-1'
			),
			'queues' => array(
				'foo' => 'http://fake.local'
			)
		));
		$client = $this->getMock('\Aws\Sqs\SqsClient', array('sendMessageBatch'), array(), '', false);
		$queue = new SimpleQueue;
		$queue->client($client);

		$model = $this->getMock('\Guzzle\Service\Resource\Model');
		$model->expects($this->never())->method('get');

		$client->expects($this->once())->method('sendMessageBatch')
			->with(array(
				'QueueUrl' => 'http://fake.local',
				'Entries' => array(
					array('Id' => 'a', 'MessageBody' => json_encode(array(1))),
					array('Id' => 'a1', 'MessageBody' => json_encode(array(2))),
				)
			))
			->will($this->throwException(new SQSTestException('You fail')));

		$data = array(
			array(1),
			array(2)
		);
		$this->assertFalse($queue->sendBatch('foo', $data));
	}

/**
 * Tests receiveMessage method
 *
 * @return void
 */
	public function testReceiveMessage() {
		Configure::write('SQS', array(
			'connection' => array(
				'key' => 'a',
				'secret' => 'b',
				'region' => 'us-east-1'
			),
			'queues' => array(
				'foo' => 'http://fake.local'
			)
		));
		$client = $this->getMock('\Aws\Sqs\SqsClient', array('receiveMessage'), array(), '', false);
		$queue = new SimpleQueue;
		$queue->client($client);

		$client->expects($this->once())->method('receiveMessage')
			->with(array(
				'QueueUrl' => 'http://fake.local',
			))
			->will($this->returnValue('yay!'));
		$this->assertEquals('yay!', $queue->receiveMessage('foo'));
	}

/**
 * Tests receiveMessage method exception
 *
 * @return void
 */
	public function testReceiveMessageException() {
		Configure::write('SQS', array(
			'connection' => array(
				'key' => 'a',
				'secret' => 'b',
				'region' => 'us-east-1'
			),
			'queues' => array(
				'foo' => 'http://fake.local'
			)
		));
		$client = $this->getMock('\Aws\Sqs\SqsClient', array('receiveMessage'), array(), '', false);
		$queue = new SimpleQueue;
		$queue->client($client);

		$client->expects($this->once())->method('receiveMessage')
			->with(array(
				'QueueUrl' => 'http://fake.local',
			))
			->will($this->throwException(new SQSTestException('You fail')));
		$this->assertFalse($queue->receiveMessage('foo'));
	}

/**
 * Tests deleteMessage method
 *
 * @return void
 */
	public function testDeleteMessage() {
		Configure::write('SQS', array(
			'connection' => array(
				'key' => 'a',
				'secret' => 'b',
				'region' => 'us-east-1'
			),
			'queues' => array(
				'foo' => 'http://fake.local'
			)
		));
		$client = $this->getMock('\Aws\Sqs\SqsClient', array('deleteMessage'), array(), '', false);
		$queue = new SimpleQueue;
		$queue->client($client);

		$client->expects($this->once())->method('deleteMessage')
			->with(array(
				'QueueUrl' => 'http://fake.local',
				'ReceiptHandle' => 'bar'
			))
			->will($this->returnValue('yay!'));
		$this->assertEquals('yay!', $queue->deleteMessage('foo', 'bar'));
	}

/**
 * Tests deleteMessage method with exception
 *
 * @return void
 */
	public function testDeleteMessageException() {
		Configure::write('SQS', array(
			'connection' => array(
				'key' => 'a',
				'secret' => 'b',
				'region' => 'us-east-1'
			),
			'queues' => array(
				'foo' => 'http://fake.local'
			)
		));
		$client = $this->getMock('\Aws\Sqs\SqsClient', array('deleteMessage'), array(), '', false);
		$queue = new SimpleQueue;
		$queue->client($client);

		$client->expects($this->once())->method('deleteMessage')
			->with(array(
				'QueueUrl' => 'http://fake.local',
				'ReceiptHandle' => 'bar'
			))
			->will($this->throwException(new SQSTestException('You fail')));
		$this->assertFalse($queue->deleteMessage('foo', 'bar'));
	}

/**
 * Date provider for test_handleException
 *
 * @return array
 */
	public function _handleExceptionDataProvider() {
		return array(
			array('AccessDenied'),
			array('AuthFailure'),
			array('InvalidAccessKeyId'),
			array('InvalidAction'),
			array('InvalidAddress'),
			array('InvalidHttpRequest'),
			array('InvalidRequest'),
			array('InvalidSecurity'),
			array('InvalidSecurityToken'),
			array('InvalidClientTokenId'),
			array('MissingClientTokenId'),
			array('MissingCredentials'),
			array('MissingParameter'),
			array('X509ParseError'),
			array('AWS.SimpleQueueService.NonExistentQueue'),
			array('ServiceUnavailable', false),
			array('InternalError', false)
		);
	}

/**
 * Test that some exception codes is fatal and other
 * isn't
 *
 * @dataProvider _handleExceptionDataProvider
 * @return void
 */
	public function test_handleException($code, $throwException = true) {
		$exception = new SQSTestException('Sample');
		$exception->setExceptionCode($code);

		if ($throwException) {
			$this->setExpectedException('SQSTestException');
		}

		$queue = new SimpleQueue;

		$ReflectionMethod = new ReflectionMethod('SimpleQueue', '_handleException');
		$ReflectionMethod->setAccessible(true);
		$ReflectionMethod->invokeArgs($queue, [$exception]);
	}

/**
 * Test that 25 non-fatal exceptions will still re-throw whatever
 * exception that there may come by
 *
 * @expectedException SQSTestException
 * @expectedExceptionMessage Sample
 * @return void
 */
	public function test_handleExceptionNonFatalMaxCount() {
		$exception = $this->getMock('SQSTestException', array(), array('Sample'));
		$exception
			->expects($this->exactly(26))
			->method('getExceptionCode')
			->with()
			->will($this->returnValue('ImNotFatal'));

		$queue = new SimpleQueue();

		$ReflectionMethod = new ReflectionMethod('SimpleQueue', '_handleException');
		$ReflectionMethod->setAccessible(true);

		for ($i = 0; $i < 30; $i++) {
			$ReflectionMethod->invokeArgs($queue, [$exception]);
		}
	}

}
