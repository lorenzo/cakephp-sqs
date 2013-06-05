# AWS Simple Queue Service for CakePHP #

[![Build Status](https://travis-ci.org/lorenzo/cakephp-sqs.png?branch=master)](https://travis-ci.org/lorenzo/cakephp-sqs)

This plugin is an adaptor for the original AWS SDK classes related to the Simple Queue Service (SQS). What in offers is an utility
class that will handle construction and configuration of the HTTP client based on the information stored in the Configure class.

Additionally, it provides a Task class that will help you build long lived worker processes in no time.

## Requirements ##

* CakePHP 2.x
* PHP 5.3+
* Composer

## Installation ##

The only installation method supported by this plugin is by using composer. Just add this to your composer.json configuration:

	{
	  "extra": {
		"installer-paths": {
				"app/Plugin/SQS": ["lorenzo/cakephp-sqs"]
		}
	  },
	  "require" : {
		"lorenzo/cakephp-sqs": "master"
	  }
	}

### Enable plugin

You need to enable the plugin your `app/Config/bootstrap.php` file:

    CakePlugin::load('SQS');

### Configuration

The plugin expects the following configuration data in the Configure keys:

	Configure::write('SQS', array(
		'connection' => array(
			'key' => 'Your amazon key',
			'secret' => 'Your amazon secret',
			'region' => 'eu-west-1' // Or any region you want
		),
		'queues' => array(
			'my_first_queue' => 'https://sqs.eu-west-1.amazonaws.com/123456/my_first_queue',
			'my_second_queue' => 'https://sqs.eu-west-1.amazonaws.com/123456/my_first_queue',
		)
	));

## Storing a message in the Queue

	ClassRegistry::init('SQS.SimpleQueue')->send('my_first_queue', array('some' => 'data'));

## Storing multiple messages as a batch

	ClassRegistry::init('SQS.SimpleQueue')->sendBatch('my_first_queue', array(
		array('some' => 'data1'),
		array('another' => 'message'),
		'plain data',
		...
	));

The return value of this method is an array with all messages that could not be stored (if any), having the same numeric position
in the array as the one you sent. For example if the second and forth messages in your array failed, then the array will contain:

	array(1 => 'Error Message', 3 => 'Another error message')

## Receiving a message from a queue

	$message = ClassRegistry::init('SQS.SimpleQueue')->receiveMessage('my_first_queue');

The return value is unaltered from the AWS SDK. Please refer to its documentation for more info.

## Deleting a message from a queue

	$message = ClassRegistry::init('SQS.SimpleQueue')->receiveMessage('my_first_queue');
	ClassRegistry::init('SQS.SimpleQueue')->deleteMessage('my_first_queue', $message->get('ReceiptHandle'));


## Setting up a worker shell

As mentioned before, this plugin helps you create long lived worker shells that will process jobs one at a time as
messages are received from a set of queues in SQS. This is an example

	<?php
	class MyWorkerShell extends AppShell {

		/**
		 * Adding the SQS worker shell
		 *
		 * @return void
		 */
		public $tasks = array('SQS.QueueWorker');

		public function main() {
			$this->QueueWorker->addFunction('my_first_queue', $this, 'handleJob');
			$this->QueueWorker->work();
		}

		public function handleJob($data) {
			// Do some stuff

			// Tell SQS to delete the message if everything went ok
			if ($someCondition) {
				return true;
			}

			// Something went wrong, re-queue the message
			return false;
		}

	}

The functions registered to handle jobs will receive the message body from the queue after decoding it using `json_decode`.
