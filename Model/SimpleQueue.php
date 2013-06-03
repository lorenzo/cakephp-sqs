<?php

use Aws\Sqs\SqsClient;

App::uses('CakeLog', 'Log');
App::uses('Configure', 'Core');

/**
 * An utility wrapper for AWS Simple Queue System. This will handle the connection
 * creation and offers some wrappers around the message creation methods to marshal
 * any provided data.
 **/
class SimpleQueue {

/**
 * Holds a reference to a SqsClient connection
 *
 * @car SqsClient
 **/
	protected $_client = null;

/**
 * Gets the configured client connection to SQS. If none is set, it will create
 * a new one out of the configuration stored using the Configure class. It is also
 * possible to provide you own client instance already configured and initialized.
 *
 * @param SqsClient $client if set null current configured client will be used
 *  if set to false, currently configured client will be destroyed
 **/
	public function client($client = null) {
		if ($client instanceof SqsClient) {
			$this->_client = $client;
		}

		if ($client === false) {
			return $this->_client = null;
		}

		if (empty($this->_client)) {
			$config = Configure::read('SQS');
			$this->_client = SqsClient::factory($config['connection']);
		}

		return $this->_client;
	}

/**
 * Stores a new message in the queue so it an external client can work upon it
 *
 * @param string $taskName a task name as defined in the configure key SQS.queues
 * @param mixed $data payload data to associate to the new queued message
 * @return boolean success
 **/
	public function send($taskName, $data = null) {
		$url = $this->_taskURL($taskName);
		$data = json_encode($data);
		CakeLog::debug(sprintf('Creating background job: %s', $taskName), array('sqs'));

		try {
			$result = $this->client()->sendMessage(array(
				'QueueUrl' => $url,
				'MessageBody' => $data
			))->get('MessageId'); 
		} catch (Exception $e) {
			CakeLog::error($e->getMessage(), 'sqs');
			$result = false;
		}

		if (empty($result)) {
			CakeLog::error(
				sprintf('Could not create background job for task %s', $taskName),
				array('sqs')
			);
			return false;
		}

		return true;
	}

/**
 * Stores multiple messages in the queue so it an external client can work upon them.
 * For performance reasons, it is better to create jobs in batches instead of one a time
 * if you plan to create several jobs in the same process or request.
 *
 * @param string $taskName a task name as defined in the configure key SQS.queues
 * @param array $payloads list of payload data to associate to the new queued message
 * for each entry in the array a new message in the queue will be created
 * @return array list of messages that failed to be sent or false if an exception was caught
 **/
	public function sendBatch($taskName, array $payloads) {
		$url = $this->_taskURL($taskName);

		try {
			CakeLog::debug(sprintf('Creating %d background jobs: %s', count($payloads), $taskName), array('sqs'));
			$result = $this->client()->sendMessageBatch(array(
				'QueueUrl' => $url,
				'Entries' => array_map(function($e) use (&$i) {
					return array('Id' => 'a' . ($i++), 'MessageBody' => json_encode($e));
				}, $payloads)
			));

			$failed = [];
			foreach ((array)$result->get('Failed') as $f) {
				$failed[(int)$f['Id']] = $f['Message'];
			}

			if (!empty($failed)) {
				CakeLog::warning(sprintf('Failed sending %d messages for queue: %s', count($failed), $taskName), array('sqs'));
			}

			return $failed;
		} catch (Exception $e) {
			CakeLog::error($e->getMessage(), 'sqs');
		}

		return false;
	}

/**
 * Gets a pending message for an specific queue.
 *
 * @param string $taskName the name of the queue for which you want to get one message
 * @return Guzzle\Service\Resource\Model
 * @see http://docs.aws.amazon.com/aws-sdk-php-2/latest/class-Aws.Sqs.SqsClient.html#_receiveMessage
 */
	public function receiveMessage($taskName) {
		$url = $this->_taskURL($taskName);

		try {
			return $this->client()->receiveMessage(array(
				'QueueUrl' => $url
			));
		} catch (Exception $e) {
			CakeLog::error($e->getMessage(), 'sqs');
			return false;
		}
	}

/**
 * Deletes a message from the specified task queue. This is used to acknowledge that
 * the message was received and that it should not be enqueued again.
 *
 * @param string $taskName the name of the queue for which you want to delete one message
 * @param string $id the ResourceHandle string originally received with the message
 * @return Guzzle\Service\Resource\Model or false if an exception is caught
 * @see http://docs.aws.amazon.com/aws-sdk-php-2/latest/class-Aws.Sqs.SqsClient.html#_deleteMessage
 */
	public function deleteMessage($taskName, $id) {
		$url = $this->_taskURL($taskName);

		try {
			return $this->client()->deleteMessage(array(
				'QueueUrl' => $url,
				'ReceiptHandle' => $id
			));
		} catch (Exception $e) {
			CakeLog::error($e->getMessage(), 'sqs');
			return false;
		}

	}

/**
 * Returns the url for an specific task name as configured
 *
 * @param string $taskName
 * @return string
 */
	protected function _taskURL($taskName) {
		$url = Configure::read('SQS.queues.' . $taskName);
		if (empty($url)) {
			throw new InvalidArgumentException("$taskName URL was not configured. Use Configure::write(SQS.queue.$taskName, \$url)");
		}
		return $url;
	}
}
