<?php

use Aws\Sqs\SqsClient;

App::uses('SimpleQueue', 'SQS.Model');

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

		$class = $this->getMockClass('BaseLog', ['write']);
		CakeLog::config('queuetest', array(
			'engine' => $class,
			'types' => array('error', 'debug'),
			'scopes' => ['sqs']
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
				'MessageBody' => json_encode(['my' => 'data'])
			))
			->will($this->returnValue($model));

		$this->assertTrue($queue->send('foo', ['my' => 'data']));
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
				'MessageBody' => json_encode(['my' => 'data'])
			))
			->will($this->returnValue($model));

		$this->assertFalse($queue->send('foo', ['my' => 'data']));
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
				'MessageBody' => json_encode(['my' => 'data'])
			))
			->will($this->throwException(new Exception('you fail')));

		$this->assertFalse($queue->send('foo', ['my' => 'data']));
	}

/**
 * Tests send method with missing config for queue
 *
 * @expectedException InvalidArgumentException
 * @expectedExceptionMessage foo URL was not configured. Use Configure::write(SQS.queue.foo, $url)
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
		$queue->send('foo', ['my' => 'data']);
	}
}
