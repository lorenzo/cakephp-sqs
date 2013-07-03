<?php

App::uses('AppShell', 'Console/Command');
App::uses('CakeEvent', 'Event');
App::uses('CakeEventManager', 'Event');
App::uses('ClassRegistry', 'Utility');

/**
 * Utility functions to setup a worker server for SQS
 *
 */
class QueueWorkerTask extends AppShell {

/**
 * Internal reference to the SimpleQueue library
 *
 * @var SimpleQueue
 */
	protected $_worker;

/**
 * Internal reference to the CakeEventManager
 *
 * @var CakeEventManager
 */
	protected $_eventManager;

/**
 * List of worker functions that will be internally sub-dispatched
 *
 * @var array
 */
	protected $_callbacks;

/**
 *
 * @param string $name the name of the task this worker implements
 * @return void
 */
	public function work($name = 'default') {
		$this->log(sprintf("Starting %s worker", $name), 'info', 'sqs');
		$worker = $this->getWorker();

		while (true) {
			foreach ($this->_callbacks as $queue => $callback) {
				if (!$this->_triggerEvent('Queue.beforeWork')) {
					break 2;
				}

				$job = $worker->receiveMessage($queue);
				if (!empty($job) && $job->get('Messages')) {
					$this->_work($queue, $job);
				}

				if (!$this->_triggerEvent('Queue.afterWork')) {
					break 2;
				}
			}
		}
	}

/**
 * Get the client object
 *
 * @return SimpleQueue
 */
	public function getWorker() {
		if (empty($this->_worker)) {
			$this->_worker = ClassRegistry::init('SQS.SimpleQueue');
		}
		return $this->_worker;
	}

/**
 * Registers an object method to be called as a worker function for a specific task name
 *
 * @param string $name the name of the queue to susbscribe to
 * @param object|callable $object the object that contains the worker method
 * @param string $method the name of the method that will be called with the job
 * @return void
 */
	public function addFunction($name, $object, $method = null) {
		if ($method) {
			$this->_callbacks[$name] = array($object, $method);
		} else {
			$this->_callbacks[$name] = $object;
		}
	}

/**
 * Get the Event Manager
 *
 * If none exist it creates a new instance
 *
 * @return CakeEventManager
 */
	public function getEventManager() {
		if ($this->_eventManager === null) {
			$this->_eventManager = new CakeEventManager;
		}
		return $this->_eventManager;
	}

/**
 * Trigger a Gearman event
 *
 * @param string $name The event name
 * @param mixed $data The event data
 * @return boolean If the event was stopped or not
 */
	protected function _triggerEvent($name, $data = null) {
		$event = new CakeEvent($name, $this, $data);
		$this->getEventManager()->dispatch($event);
		return !$event->isStopped();
	}

/**
 * The function that is used for all jobs, it will sub-dispatch to the real function
 * Useful for registering closures
 *
 * @return void
 */
	public function _work($name, $job) {
		foreach ($job->get('Messages') as $message) {
			$data = json_decode($message['Body'], true);
			$return = call_user_func($this->_callbacks[$name], $data, $message['ReceiptHandle']);
			if ($return === true) {
				$this->getWorker()->deleteMessage($name, $message['ReceiptHandle']);
			}
		}
	}

}
