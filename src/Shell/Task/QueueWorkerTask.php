<?php
namespace CakeSQS\Shell\Task;

use CakeSQS\SimpleQueue;
use Cake\Console\Shell;
use Cake\Event\Event;
use Cake\Event\EventManager;

/**
 * QueueWorkerTask shell task.
 */
class QueueWorkerTask extends Shell
{
    const MAX_MESSAGES_PROCESSED = 1000;

    /**
     * Internal reference to the SimpleQueue library
     *
     * @var SimpleQueue
     */
    protected $simpleQueue;

    /**
     * Internal reference to the EventManager
     *
     * @var EventManager
     */
    protected $eventManager;

    /**
     * List of worker functions that will be internally sub-dispatched
     *
     * @var array
     */
    protected $callbacks = [];

    /**
     * Read the queue and process messages, this work process will block for a number of
     * iterations, so can set this work process to last forever setting the $iterations param
     * to -1, we don't recommend this, but set a fixed amount of iterations, and restart the
     * process again when done via monit, gearman and others.
     *
     * @param string $name the name of the task this worker implements
     * @param int $iterations max amount of messages to process before finishing the worker loop, set as -1
     * for infinite working loop
     * @return void
     */
    public function work($name = 'default', $iterations = self::MAX_MESSAGES_PROCESSED)
    {
        $this->log(sprintf("Starting %s worker", $name), 'info', 'sqs');
        $simpleQueue = $this->getSimpleQueue();
        $i = 0;
        while ($i < $iterations) {
            foreach ($this->callbacks as $queue => $callback) {
                if (!$this->_triggerEvent('Queue.beforeWork')) {
                    break 2;
                }

                $job = $simpleQueue->receiveMessage($queue);
                if (!empty($job) && $job->get('Messages')) {
                    $this->_work($queue, $job);
                }

                if (!$this->_triggerEvent('Queue.afterWork')) {
                    break 2;
                }
            }
            if ($iterations > -1) {
                $i++;
            }
        }
        $this->log(sprintf("Finished %s worker", $name), 'info', 'sqs');
    }

    /**
     * Get the client object
     *
     * @return SimpleQueue
     */
    public function getSimpleQueue()
    {
        if (empty($this->simpleQueue)) {
            $this->simpleQueue = new SimpleQueue();
        }

        return $this->simpleQueue;
    }

    /**
     * Registers a callback as a worker function for a specific task name
     *
     * @param string $name the name of the queue to subscribe to
     * @param callable $callback the object that contains the worker method
     * @return void
     */
    public function addFunction($name, $callback)
    {
        $this->log(sprintf('Adding callback "%s" for queue "%s"', $name, $this->getSimpleQueue()->queueUrl($name)), 'info', 'sqs');

        if (!is_callable($callback)) {
            throw new \RuntimeException(sprintf('Invalid callback, provided object must be callable'));
        }

        $this->callbacks[$name] = $callback;
    }

    /**
     * Get the Event Manager
     *
     * If none exist it creates a new instance
     *
     * @return EventManager
     */
    public function getEventManager()
    {
        if ($this->eventManager === null) {
            $this->eventManager = EventManager::instance();
        }

        return $this->eventManager;
    }

    /**
     * Trigger an event
     *
     * @param string $name The event name
     * @param mixed $data The event data
     * @return bool If the event was stopped or not
     */
    protected function _triggerEvent($name, $data = null)
    {
        $event = new Event($name, $this, $data);
        $this->getEventManager()->dispatch($event);

        return !$event->isStopped();
    }

    /**
     * The function that is used for all jobs, it will sub-dispatch to the real function
     * Useful for registering closures
     *
     * @param string $name name of the queue
     * @param \Aws\Result $job message
     * @return void
     */
    protected function _work($name, $job)
    {
        foreach ($job->get('Messages') as $message) {
            $data = json_decode($message['Body'], true);
            $return = call_user_func($this->callbacks[$name], $data, $message['ReceiptHandle'], $message['MessageAttributes']);
            if ($return === true) {
                $this->getSimpleQueue()->deleteMessage($name, $message['ReceiptHandle']);
            }
        }
    }
}
