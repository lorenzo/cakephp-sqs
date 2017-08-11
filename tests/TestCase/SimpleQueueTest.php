<?php
namespace CakeSQS\Test;

use Aws\Result;
use Cake\TestSuite\TestCase;
use CakeSQS\SimpleQueue;
use VCR\VCR;

class SimpleQueueTest extends TestCase
{
    /**
     * @var SimpleQueue
     */
    protected $SimpleQueue;

    /**
     * setUp method
     *
     * @return void
     */
    public function setUp()
    {
        parent::setUp();
        $this->SimpleQueue = new SimpleQueue();
        $this->SimpleQueue->setConfig([
            'connection.credentials' => [
                'key' => 'SOMEKEY',
                'secret' => 'SOMESECRET',
            ],
            'queues.testQueue1' => 'https://sqs.eu-central-1.amazonaws.com/925075006686/testQueue1'
        ]);

        VCR::configure()
            ->enableRequestMatchers(['method', 'url', 'body'])
            ->setMode('none');
    }

    /**
     * tearDown method
     *
     * @return void
     */
    public function tearDown()
    {
        unset($this->SimpleQueue);

        parent::tearDown();
    }

    public function testClientFalse()
    {
        $this->assertNull($this->SimpleQueue->client(false));
    }

    /**
     * @vcr SimpleQueueTest_testSend
     */
    public function testSend()
    {
        $expected = [
            'statusCode' => 200,
            'effectiveUri' => 'https://sqs.eu-central-1.amazonaws.com/925075006686/testQueue1',
            'headers' => [
                'date' => 'Fri, 11 Aug 2017 17:57:20 GMT',
                'content-type' => 'text/xml',
                'content-length' => '378',
                'connection' => 'keep-alive',
                'x-amzn-requestid' => '584723bf-d1e0-525a-93f3-71b823a0c451'
            ],
            'transferStats' => [
                'http' => [[]]
            ]
        ];
        // Following request will be recorded once and replayed in future test runs
        $result = $this->SimpleQueue->send('testQueue1', [
            'key1' => 'value1',
            'key2' => 'value2',
        ]);
        $this->assertSame($expected, $result->get('@metadata'));
    }

    /**
     * @vcr SimpleQueueTest_testSendInvalidCredentials
     * @expectedException \Aws\Sqs\Exception\SqsException
     * @expectedExceptionMessage Error executing "SendMessage" on "https://sqs.eu-central-1.amazonaws.com/925075006686/testQueue1"; AWS HTTP error: Client error: `POST https://sqs.eu-central-1.amazonaws.com/925075006686/testQueue1` resulted in a `403 Forbidden` response:
     * <?xml version="1.0"?><ErrorResponse xmlns="http://queue.amazonaws.com/doc/2012-11-05/"><Error><Type>Sender</Type><Code>I (truncated...)
     * InvalidClientTokenId (client): The security token included in the request is invalid. - <?xml version="1.0"?><ErrorResponse xmlns="http://queue.amazonaws.com/doc/2012-11-05/"><Error><Type>Sender</Type><Code>InvalidClientTokenId</Code><Message>The security token included in the request is invalid.</Message><Detail/></Error><RequestId>067bf701-313d-53e2-9cae-a506c403e953</RequestId></ErrorResponse>
     */
    public function testSendInvalidCredentials()
    {
        $this->SimpleQueue->setConfig([
            'connection.credentials' => [
                'key' => 'INVALID',
                'secret' => 'INVALID',
            ],
            'queues.testQueue1' => 'https://sqs.eu-central-1.amazonaws.com/925075006686/testQueue1'
        ]);

        // Following request will be recorded once and replayed in future test runs
        $result = $this->SimpleQueue->send('testQueue1', [
            'key1' => 'value1',
            'key2' => 'value2',
        ]);
    }

    /**
     * @vcr SimpleQueueTest_testSendBatch
     */
    public function testSendBatch()
    {
        $result = $this->SimpleQueue->sendBatch('testQueue1', [
            ['message1' => 'value1'],
            ['message3' => 'value2'],
        ]);

        $this->assertEmpty($result);
    }

    /**
     * @vcr SimpleQueueTest_testSendBatchEmpty
     */
    public function testSendBatchEmpty()
    {
        $result = $this->SimpleQueue->sendBatch('testQueue1', [null]);

        $this->assertEmpty($result);
    }

    /**
     * @vcr SimpleQueueTest_testReceiveMessage
     */
    public function testReceiveMessage()
    {
        $expected = [
            [
                'MessageId' => '929a9cfe-0d45-494c-80fc-41ec6e2e017d',
                'ReceiptHandle' => 'AQEBEYj8zzBaKn8PpJiHgvyznyo7H0BQYH7MBy7429K/ad53lXLRh5yu2Yb0EH9o22WskOCTX7enwcGxTc7JQLQPcJwFwJB/L29pVOyDvZc8fI2XPjd+7jbN91H6PqfHUUsryiDHkA36ZH0tWKjFOVt986GKptqdON+BbinT2KIjd5NLwN2sr7kWgWKhva6YSC/BIWTsSUyAfiFGRDLksNtMiXJk2nFzwvINGU7khBdDpZ0xZxmhhPvT3TPQeSukZNEp859yZLVA9t69Vx2Rrtf/3vGfZj9NjSVrEMcquP8zDrmIicp5+ILtm1qYJxq2lsYH0LHTwGtIQC1nW+J7D/t3JAFZdgohsdXEl3T+KIig2APUgJz4Mp/ze3gzIrY7/Y+plII+MnrISdBSmDnoRRpF/g==',
                'MD5OfBody' => 'ff45cc3835165307ef414c23ca2c6f67',
                'Body' => '{"key1":"value1","key2":"value2"}'
            ]
        ];
        $msgResult = $this->SimpleQueue->receiveMessage('testQueue1');
        $this->assertSame($expected, $msgResult->search('Messages'));
    }

    /**
     * @vcr SimpleQueueTest_testDeleteMessage
     */
    public function testDeleteMessage()
    {
        $handle = 'AQEBq+H6ahm2aPuY5CfsGCFvHMXkyfAGFxLENt05KyxdJ4d7dOzk72HmWIr9HDvMHVwR2QDCvSTr2L9UjSDpjArj4sbnTKVG5RTuSX/LM7D5k5haVf9rQZEQmiqzAXpgpXINDIwSHmOmtrUXavlD+tcPDIgb7b8azopfiA00GvTOk24TGe/7FHj7KACcwm/ptoXqeNKZ/KMUA5DfI7Oaqc6ra+/N/99phj17yWbA6UPQEo5IGXMDI+TLsm6NQa7OqcFvw6JrYdbFCJboX61tYOKGn3u47ahUDY/YIMJTH/GVALuSHSzMGmZOgys6xzl95HQDmCqPnA2UN1VE1RmxYOm2beXQredSVu1TqgnaQlLIv5Y1/E9IElJJPN2ADqhGLZZyM9Vjp8uG9FQP67VL11HSIQ==';
        // Following request will be recorded once and replayed in future test runs
        $msgResult = $this->SimpleQueue->deleteMessage('testQueue1', $handle);
        $this->assertSame(200, $msgResult['@metadata']['statusCode']);
    }

    /**
     * @vcr SimpleQueueTest_testGetAttributes
     */
    public function testGetAttributes()
    {
        $msgResult = $this->SimpleQueue->getAttributes('testQueue1', ['ApproximateNumberOfMessages']);
        $this->assertSame('17', $msgResult['Attributes']['ApproximateNumberOfMessages']);
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testQueueUrl()
    {
        $this->SimpleQueue->queueUrl('notfound');
    }
}
