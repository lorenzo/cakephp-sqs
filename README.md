# AWS Simple Queue Service for CakePHP 3 #

[![Build Status](https://travis-ci.org/lorenzo/cakephp-sqs.png?branch=master)](https://travis-ci.org/lorenzo/cakephp-sqs)

This plugin is an adaptor for the original AWS SDK classes related to the Simple Queue Service (SQS). What in offers is an utility
class that will handle construction and configuration of the HTTP client based on the information stored in the Configure class.

Additionally, it provides a Task class that will help you build long lived worker processes in no time.

## Requirements ##

* CakePHP 3.4.x
* PHP 5.6+

## Installation ##

The only installation method supported by this plugin is by using composer. Just add this to your composer.json configuration:

    composer require lorenzo/cakephp-sqs

### Enable plugin

You need to enable the plugin your `config/bootstrap.php` file:

    Plugin::load('CakeSQS');

### Configuration

The plugin expects the following configuration data in the Configure keys:

    Configure::write('CakeSQS', [
        'connection' => [
            /**
             * @see http://docs.aws.amazon.com/aws-sdk-php/v3/api/class-Aws.Sqs.SqsClient.html#___construct
             * for all possible config params
             */
            'credentials' => [
                'key' => 'Your amazon key',
                'secret' => 'Your amazon secret',
            ],
            'version' => '2012-11-05', // you don't need to change this
            'region' => 'eu-central-1' // must match the region where the sqs queue was created
        ],
        'queues' => [
            'queues.testQueue1' => 'sqs queue url, for example: https://sqs.eu-central-1.amazonaws.com/12345/someQueue'
        ]
    ]);

Or you can configure the connector class via `setConfig`

    $this->SimpleQueue = new SimpleQueue();
    $this->SimpleQueue->setConfig([
        'connection.credentials' => [
            'key' => 'SOMEKEY',
            'secret' => 'SOMESECRET',
        ],
        'queues.testQueue1' => 'sqs queue url, for example: https://sqs.eu-central-1.amazonaws.com/12345/someQueue'
    ]);


## Storing a message in the Queue

    $this->SimpleQueue->send('testQueue1', [
        'key1' => 'value1',
        'key2' => 'value2',
    ]);

The return value is an \Aws\Result object 

## Storing multiple messages as a batch

    $this->SimpleQueue->send('testQueue1', [
        'key1' => 'value1',
        'key2' => 'value2',
    ]);

The return value of this method is an array with all messages that could not be stored (if any), having the same numeric position
in the array as the one you sent. For example if the second and forth messages in your array failed, then the array will contain:

    [1 => 'Error Message', 3 => 'Another error message']

## Receiving a message from a queue

    $msgResult = $this->SimpleQueue->receiveMessage('testQueue1');

The return value is unaltered from the AWS SDK. Please refer to its documentation for more info. For example:

    $msgResult = $this->SimpleQueue->receiveMessage('testQueue1');
    $msgResult->search('Messages');
    // would return
    [
        [
            'MessageId' => '929a9cfe-0d45-494c-80fc-41ec6e2e017d',
            'ReceiptHandle' => 'AQEBEYj8zzBaKn8PpJiHgvyznyo7H0BQYH7MBy7429K/ad53lXLRh5yu2Yb0EH9o22WskOCTX7enwcGxTc7JQLQPcJwFwJB/L29pVOyDvZc8fI2XPjd+7jbN91H6PqfHUUsryiDHkA36ZH0tWKjFOVt986GKptqdON+BbinT2KIjd5NLwN2sr7kWgWKhva6YSC/BIWTsSUyAfiFGRDLksNtMiXJk2nFzwvINGU7khBdDpZ0xZxmhhPvT3TPQeSukZNEp859yZLVA9t69Vx2Rrtf/3vGfZj9NjSVrEMcquP8zDrmIicp5+ILtm1qYJxq2lsYH0LHTwGtIQC1nW+J7D/t3JAFZdgohsdXEl3T+KIig2APUgJz4Mp/ze3gzIrY7/Y+plII+MnrISdBSmDnoRRpF/g==',
            'MD5OfBody' => 'ff45cc3835165307ef414c23ca2c6f67',
            'Body' => '{"key1":"value1","key2":"value2"}'
        ]
    ];

## Deleting a message from a queue

    $msgResult = $this->SimpleQueue->receiveMessage('testQueue1');
    $handle = $msgResult['Messages'][0]['ReceiptHandle'];
    $this->SimpleQueue->deleteMessage('testQueue1', $handle);


## Setting up a worker shell

As mentioned before, this plugin helps you create long lived worker shells that will process jobs one at a time as
messages are received from a set of queues in CakeSQS. This is an example

    <?php
    
    namespace App\Shell;
    
    use Cake\Console\Shell;
    use Cake\Core\Configure;
    use CakeSQS\SimpleQueue;
    
    /**
     * Sqs shell command.
     */
    class SqsShell extends Shell
    {
    
        public $tasks = ['CakeSQS.QueueWorker'];
    
        public function send()
        {
            $credentials = [
                'key' => 'xxx',
                'secret' => 'yyy',
            ];
    
            Configure::write('CakeSQS.connection.credentials', $credentials);
            Configure::write('CakeSQS.queues.testQueue1', 'https://sqs.eu-central-1.amazonaws.com/zzzz/testQueue1');
    
            $queue = new SimpleQueue();
            debug($queue->send('testQueue1', 'some-data'));
        }
    
        public function workForever()
        {
            $credentials = [
                'key' => 'xxx',
                'secret' => 'yyy',
            ];
    
            Configure::write('CakeSQS.connection.credentials', $credentials);
            Configure::write('CakeSQS.queues.testQueue1', 'https://sqs.eu-central-1.amazonaws.com/zzz/testQueue1');
    
            $this->QueueWorker->addFunction('testQueue1', function ($item) {
                debug($item);
    
                return true; // return true to delete the message upon processing, false to leave the message in the queue
            });
            $this->QueueWorker->work();
        }
    }

The functions registered to handle jobs will receive the message body from the queue after decoding it using `json_decode`.

IMPORTANT: return true to delete the message upon processing, false to leave the message in the queue and re - process later
