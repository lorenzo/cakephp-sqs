<?php
namespace CakeSQS;

use Aws\Sqs\SqsClient;
use Cake\Core\Configure;
use Cake\Core\InstanceConfigTrait;
use Cake\Log\Log;

/**
 * An utility wrapper for AWS Simple Queue System. This will handle the connection
 * creation and offers some wrappers around the message creation methods to marshal
 * any provided data.
 **/
class SimpleQueue
{
    use InstanceConfigTrait;

    protected $_defaultConfig = [
        'connection' => [
            /*
             * @see http://docs.aws.amazon.com/aws-sdk-php/v3/api/class-Aws.Sqs.SqsClient.html#___construct
             * for all possible config params
             */
            'credentials' => [
                'key' => 'Your amazon key',
                'secret' => 'Your amazon secret',
            ],
            'version' => '2012-11-05',
            'region' => 'eu-central-1' // must match the region where the sqs queue was created
        ],
        'queues' => [
            // sqs queue urls, for example: https://sqs.eu-central-1.amazonaws.com/12345/someQueue
        ]
    ];

    /**
     * SimpleQueue constructor.
     * Configuration will be loaded by default from CakeSQS key
     *
     * @param array $config Configuration override
     */
    public function __construct($config = [])
    {
        $this->setConfig(Configure::read('CakeSQS'));
        $this->setConfig($config);
    }

    /**
     * Holds a reference to a SqsClient connection
     *
     * @var SqsClient
     **/
    protected $_client = null;

    /**
     * The number of exceptions that have been caught
     * that weren't fatal
     *
     * @var int
     */
    protected $_exceptionCount = 0;

    /**
     * Gets the configured client connection to CakeSQS. If none is set, it will create
     * a new one out of the configuration stored using the Configure class. It is also
     * possible to provide you own client instance already configured and initialized.
     *
     * @param SqsClient $client if set null current configured client will be used
     *  if set to false, currently configured client will be destroyed
     *
     * @return SqsClient|null
     */
    public function client($client = null)
    {
        if ($client instanceof SqsClient) {
            $this->_client = $client;
        }

        if ($client === false) {
            return $this->_client = null;
        }

        if (empty($this->_client)) {
            $this->_client = new SqsClient($this->getConfig('connection'));
        }

        return $this->_client;
    }

    /**
     * Stores a new message in the queue so an external client can work upon it
     *
     * @param string $taskName a task name as defined in the configure key CakeSQS.queues
     * @param mixed $data payload data to associate to the new queued message
     * @return \Aws\Result|null success
     **/
    public function send($taskName, $data = null)
    {
        $url = $this->queueUrl($taskName);
        $data = json_encode($data);

        $result = $this->client()->sendMessage([
            'QueueUrl' => $url,
            'MessageBody' => $data,
        ]);

        if (empty($result->get('MessageId'))) {
            Log::error(sprintf('Could not create background job for task %s', $taskName));

            return null;
        }

        return $result;
    }

    /**
     * Stores multiple messages in the queue so it an external client can work upon them.
     * For performance reasons, it is better to create jobs in batches instead of one a time
     * if you plan to create several jobs in the same process or request.
     *
     * @param string $taskName a task name as defined in the configure key CakeSQS.queues
     * @param array $payloads list of payload data to associate to the new queued message
     * for each entry in the array a new message in the queue will be created
     *
     * @return array list of messages that failed to be sent or false if an exception was caught
     **/
    public function sendBatch($taskName, array $payloads)
    {
        $url = $this->queueUrl($taskName);

        $result = $this->client()->sendMessageBatch([
            'QueueUrl' => $url,
            'Entries' => array_map(function ($e) use (&$i) {
                return ['Id' => 'a' . ($i++), 'MessageBody' => json_encode($e)];
            }, $payloads)
        ]);

        $failed = [];
        foreach ((array)$result->get('Failed') as $f) {
            $failed[(int)substr($f['Id'], 1)] = $f['Message'];
        }

        if (!empty($failed)) {
            Log::warning(sprintf('Failed sending %d messages for queue: %s', count($failed), $taskName));
        }

        return $failed;
    }

    /**
     * Gets a pending message for an specific queue.
     *
     * @param string $taskName the name of the queue for which you want to get one message
     * @param array $options options to be passed to receiveMessage method (it may include ReceiveMessages and WaitTimeSeconds for long polling)
     * @return \Aws\Result|false
     * @see http://docs.aws.amazon.com/aws-sdk-php-2/latest/class-Aws.Sqs.SqsClient.html#_receiveMessage
     */
    public function receiveMessage($taskName, $options = [])
    {
        $url = $this->queueUrl($taskName);

        $options['QueueUrl'] = $url;
        $options['MessageAttributeNames'] = ['All'];
        return $this->client()->receiveMessage($options);
    }

    /**
     * Deletes a message from the specified task queue. This is used to acknowledge that
     * the message was received and that it should not be enqueued again.
     *
     * @param string $taskName the name of the queue for which you want to delete one message
     * @param string $id the ResourceHandle string originally received with the message
     * @return \Aws\Result|false
     * @see http://docs.aws.amazon.com/aws-sdk-php-2/latest/class-Aws.Sqs.SqsClient.html#_deleteMessage
     */
    public function deleteMessage($taskName, $id)
    {
        $url = $this->queueUrl($taskName);

        return $this->client()->deleteMessage([
            'QueueUrl' => $url,
            'ReceiptHandle' => $id
        ]);
    }

    /**
     * Gets queue attributes
     *
     * @param string $taskName the name of the queue for which you want to get one message
     * @param array $attributes list with attributes that you want to receive
     * @return \Aws\Result|false
     */
    public function getAttributes($taskName, $attributes)
    {
        $url = $this->queueUrl($taskName);

        return $this->client()->getQueueAttributes([
            'QueueUrl' => $url,
            'AttributeNames' => $attributes,
        ]);
    }

    /**
     * Returns the url for an specific task name as configured
     *
     * @param string $taskName name of the queue
     * @return string
     */
    public function queueUrl($taskName)
    {
        $url = $this->getConfig('queues.' . $taskName);
        if (empty($url)) {
            throw new \InvalidArgumentException("$taskName URL was not configured. Use Configure::write('CakeSQS.queues.$taskName', '\$url');");
        }

        return $url;
    }
}
