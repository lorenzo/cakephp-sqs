<?php

namespace CakeSQS\Test\Shell\Task;

use CakeSQS\Shell\Task\QueueWorkerTask;
use Cake\Core\Configure;
use Cake\Event\Event;
use Cake\Event\EventManager;
use Cake\Log\Log;
use Cake\TestSuite\TestCase;

/**
 * Tests SimpleQueuesTable class
 *
 **/
class QueueWorkerTaskTest extends TestCase
{

    /**
     * Sets up a mocked logger stream
     *
     * @return void
     **/
    public function setUp()
    {
        parent::setUp();
        $this->task = new QueueWorkerTask();
    }

    /**
     * Restores everything back to normal
     *
     * @return void
     **/
    public function tearDown()
    {
        parent::tearDown();
        Log::drop('queuetest');
        Configure::write('CakeSQS', []);
        unset($this->logger);
    }

    /**
     * Tests getWorker function
     *
     * @return void
     */
    public function testGetSimpleQueue()
    {
        $this->assertInstanceOf('\CakeSQS\SimpleQueue', $this->task->getSimpleQueue());
    }

    /**
     * Tests getEventManager function
     *
     * @return void
     */
    public function testGetEventManager()
    {
        $this->assertInstanceOf('\Cake\Event\EventManager', $this->task->getEventManager());
    }

    /**
     * Tests the infinite-looping worker function
     *
     * @expectedException Exception
     * @expectedExceptionMessage break the cycle
     * @return void
     */
    public function testWork()
    {
        Configure::write('CakeSQS.queues.job1', 'http://ok.dk/');
        Configure::write('CakeSQS.queues.job2', 'http://ok.dk/');

        /**
         * @var QueueWorkerTask
         */
        $task = $this->getMockBuilder('\CakeSQS\Shell\Task\QueueWorkerTask')
            ->setMethods(['getEventManager', 'log', 'getSimpleQueue'])
            ->getMock();

        // Making a mocked SimpleQueuesTable and making getWorker always return it
        $queue = $this->getMockBuilder('\CakeSQS\SimpleQueue')
            ->setMethods(['receiveMessage', 'deleteMessage'])
            ->getMock();

        $task
            ->expects($this->any())
            ->method('getSimpleQueue')
            ->will($this->returnValue($queue));

        //Making a mocked CakeEventManager and making getEventManager always return it
        $manager = $this->getMockBuilder('\Cake\Event\EventManager')
            ->setMethods(['dispatch'])
            ->getMock();

        $task
            ->expects($this->any())
            ->method('getEventManager')
            ->will($this->returnValue($manager));

        $manager
            ->expects($this->at(0))
            ->method('dispatch')
            ->with(new Event('Queue.beforeWork', $task));
        $manager
            ->expects($this->at(1))
            ->method('dispatch')
            ->with(new Event('Queue.afterWork', $task));

        // Faking the first message that will be received from the job1 queue
        $message = [['ReceiptHandle' => 'myId', 'Body' => json_encode('foo')]];
        $model = $this->getMockBuilder('\Aws\Result')
            ->setMethods(['get'])
            ->getMock();
        $model
            ->expects($this->exactly(2))
            ->method('get')
            ->with('Messages')
            ->will($this->returnValue($message));
        $queue
            ->expects($this->at(0))
            ->method('receiveMessage')
            ->with('job1')
            ->will($this->returnValue($model));

        // Telling the task to handle the queue job1 with a method in this tests class
        $task->addFunction('job1', [$this, 'handleJob']);

        // the second queue to manage will be job2
        $manager
            ->expects($this->at(2))
            ->method('dispatch')
            ->with(new Event('Queue.beforeWork', $task));
        $manager
            ->expects($this->at(3))
            ->method('dispatch')
            ->with(new Event('Queue.afterWork', $task));

        // Faking the first message that will be received from the job2 queue
        $message = [['ReceiptHandle' => 'mySecondID', 'Body' => json_encode('foo2')]];
        $model = $this->getMockBuilder('\Aws\Result')
            ->setMethods(['get'])
            ->getMock();
        $model
            ->expects($this->exactly(2))
            ->method('get')
            ->with('Messages')
            ->will($this->returnValue($message));
        $queue
            ->expects($this->at(2))
            ->method('receiveMessage')
            ->with('job2')
            ->will($this->returnValue($model));

        // Telling the task to handle the queue job2 with a method in this tests class
        $task->addFunction('job2', [$this, 'handleJob2']);

        // In the next cycle of the infinte loop it is the turn to manage job1 again
        $manager
            ->expects($this->at(4))
            ->method('dispatch')
            ->with(new Event('Queue.beforeWork', $task));

        // Faking the first message that will be received from the job1 queue
        // This time and exception will be thrown to break the infinite loop
        $message = [['ReceiptHandle' => 'myThirdId', 'Body' => json_encode('foo3')]];
        $model = $this->getMockBuilder('\Aws\Result')
            ->setMethods(['get'])
            ->getMock();
        $model
            ->expects($this->exactly(2))
            ->method('get')
            ->with('Messages')
            ->will($this->returnValue($message));
        $queue
            ->expects($this->at(3))
            ->method('receiveMessage')
            ->with('job1')
            ->will($this->returnValue($model));

        // Only the first handleJob function returns true, so only message will be deleted
        $queue->expects($this->once())->method('deleteMessage')->with('job1', 'myId');

        $task->work(100);
    }

    /**
     * Function that will handle jobs generated by the first queue
     * when data equals foo3 it will throw an exception
     *
     * @return boolean
     */
    public function handleJob($data)
    {
        if ($data === 'foo3') {
            throw new \Exception('break the cycle');
        }

        $this->assertEquals('foo', $data);

        return true;
    }

    /**
     * Function that will handle jobs generated by the second queue
     *
     * @return boolean
     */
    public function handleJob2($data)
    {
        $this->assertEquals('foo2', $data);

        return false;
    }
}
