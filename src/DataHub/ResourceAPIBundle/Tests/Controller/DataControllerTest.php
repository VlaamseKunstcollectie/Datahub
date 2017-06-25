<?php

namespace DataHub\ResourceAPIBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DataControllerTest extends WebTestCase
{

    private $client;

    private $record;

    private $dataPid;

    protected function setUp() {
        $this->client = static::createClient();
        $this->validRecord = file_get_contents(__DIR__.'/../Fixtures/LIDO-Example_FMobj00154983-LaPrimavera.xml');
        $this->invalidRecord = file_get_contents(__DIR__.'/../Fixtures/LIDO-Example_FMobj00154983-LaPrimavera-invalid-lido.xml');
        $this->jsonRecord = file_get_contents(__DIR__.'/../Fixtures/LIDO-Example_FMobj00154983-LaPrimavera.json');
        $this->emptyRecord = '';
        $this->dataPid = 'DE-Mb112/lido-obj00154983';
    }

    protected function getAccessToken() {
        $this->client->request('GET', '/oauth/v2/token?grant_type=password&username=admin&password=datahub&client_id=slightlylesssecretpublicid&client_secret=supersecretsecretphrase');
        $response = $this->client->getResponse();
        $data = json_decode($response->getContent(), true);
        return $data['access_token'];
    }

    protected function get($id = null) {
        $accessToken = $this->getAccessToken();
        $action = (!is_null($id)) ? sprintf("/api/v1/data/%s", urlencode($id)) : "/api/v1/data";
        $action = sprintf('%s?access_token=%s', $action, $accessToken);

        $this->client->request('GET', $action);

        return $this->client->getResponse();
    }

    protected function post($record) {
        $accessToken = $this->getAccessToken();
        $action = sprintf('/api/v1/data?access_token=%s', $accessToken);

        $this->client->request('POST', $action, [], [], ['CONTENT_TYPE' => 'application/lido+xml'], $record);

        return $this->client->getResponse();
    }

    protected function put($id, $record) {
        $accessToken = $this->getAccessToken();
        $action = sprintf('/api/v1/data/%s?access_token=%s', $id, $accessToken);

        $this->client->request('PUT', $action, [], [], ['CONTENT_TYPE' => 'application/lido+xml'], $record);

        return $this->client->getResponse();
    }

    protected function delete($id) {
        $accessToken = $this->getAccessToken();
        $action = sprintf("/api/v1/data/%s?access_token=%s", $id, $accessToken);

        $this->client->request('DELETE', $action);

        return $this->client->getResponse();
    }

    public function testGetRecordJSONAction() {
        $response = $this->post($this->validRecord);

        $response = $this->get($this->dataPid);
        $statusCode = $response->getStatusCode();
        $content = $response->getContent();

        $this->assertEquals(200, $statusCode);
        $this->assertNotEmpty($content);
        // $this->assertJsonStringEqualsJsonString($this->jsonRecord, $content);

        $this->delete($this->dataPid);
    }

    public function testGetRecordXMLAction() {
        $response = $this->post($this->validRecord);

        $response = $this->get($this->dataPid);
        $statusCode = $response->getStatusCode();
        $content = $response->getContent();

        $this->assertEquals(200, $statusCode);
        $this->assertNotEmpty($content);
        // $this->assertXMLStringEqualsXMLString($this->validRecord, $content);

        $this->delete($this->dataPid);
    }

    public function testGetRecordsAction() {
        $this->post($this->validRecord);

        $response = $this->get();
        $statusCode = $response->getStatusCode();
        $content = $response->getContent();

        // $this->assertJsonStringEqualsJsonString($this->jsonRecord, $content);

        $this->delete($this->dataPid);

        // Check if actual matches expected content

        // GET with valid pagination

        // GET with invalid pagination

        // Test with offset beyond available range
    }

    public function testPostValidRecordAction() {
        $response = $this->post($this->validRecord);
        $statusCode = $response->getStatusCode();
        $location = $response->headers->get('location');
        $dataPid = urldecode(preg_replace('/\/api\/v1\/data\/(.*)$/', '$1', $location));

        $this->assertTrue($response->isSuccessful());
        $this->assertEquals(201, $statusCode);
        $this->assertEquals($this->dataPid, $dataPid);

        $this->delete($this->dataPid);
    }

    public function testPostDuplicateRecordAction() {
        $this->post($this->validRecord);

        $response = $this->post($this->validRecord);
        $statusCode = $response->getStatusCode();
        $message = $response->headers->get('message');

        $this->assertTrue($response->isClientError());
        $this->assertEquals(409, $statusCode);
        $this->assertEquals("Record with this ID already exists.", $message);

        $this->delete($this->dataPid);
    }

    public function testPostInvalidRecordAction() {
        $response = $this->post($this->invalidRecord);
        $statusCode = $response->getStatusCode();

        // Test for 403 response
        // Test for "The provided XML record is invalid."

        $this->delete($this->dataPid);
    }

    public function testPostEmptyRecordAction() {
        $response = $this->post($this->emptyRecord);
        $statusCode = $response->getStatusCode();
        $message = $response->headers->get('message');

        $this->assertEquals(422, $statusCode);
        $this->assertEquals("No record was provided.", $message);

        $this->delete($this->dataPid);
    }

    public function testPutCreateValidRecordAction() {
        $response = $this->put($this->dataPid, $this->validRecord);
        $statusCode = $response->getStatusCode();
        $content = $response->getContent();

        $this->assertEquals(201, $statusCode);
        $this->assertEmpty($content);

        $this->delete($this->dataPid);
    }

    public function testPutUpdateValidRecordAction() {
        $response = $this->put($this->dataPid, $this->validRecord);
        $statusCode = $response->getStatusCode();
        $content = $response->getContent();

        $this->assertEquals(201, $statusCode);
        $this->assertEmpty($content);

        $response = $this->put($this->dataPid, $this->validRecord);
        $statusCode = $response->getStatusCode();
        $content = $response->getContent();

        $this->assertEquals(204, $statusCode);
        $this->assertEmpty($content);

        $this->delete($this->dataPid);
    }

    public function testPutCreateInvalidRecordAction() {
        $response = $this->put($this->dataPid, $this->validRecord);
        $statusCode = $response->getStatusCode();
        $content = $response->getContent();

        // Test for 403 response
        // Test for "The provided XML record is invalid."

        $this->delete($this->dataPid);
    }
    public function testPutUpdateInvalidRecordAction() {
        $this->post($this->validRecord);

        $response = $this->put($this->dataPid, $this->validRecord);
        $statusCode = $response->getStatusCode();
        $content = $response->getContent();

        // Test for 403 response
        // Test for "The provided XML record is invalid."

        $this->delete($this->dataPid);
    }

    public function testPutCreateEmptyRecordAction() {
        $response = $this->put($this->dataPid, $this->emptyRecord);
        $statusCode = $response->getStatusCode();
        $content = $response->getContent();

        // Test for 403 response
        // Test for "The provided XML record is invalid."

        $this->delete($this->dataPid);
    }

    public function testPutUpdateEmptyRecordAction() {
        $this->post($this->validRecord);

        $response = $this->put($this->dataPid, $this->emptyRecord);
        $statusCode = $response->getStatusCode();
        $content = $response->getContent();

        // Test for 403 response
        // Test for "The provided XML record is invalid."

        $this->delete($this->dataPid);
    }

    public function testDeleteExistingRecordAction() {
        $this->post($this->validRecord);

        $response = $this->delete($this->dataPid);
        $statusCode = $response->getStatusCode();
        $content = $response->getContent();

        $this->assertEquals(204, $statusCode);
        $this->assertEmpty($content);
    }

    public function testDeleteNonExistingRecordAction() {
        $this->delete($this->dataPid);

        $response = $this->delete($this->dataPid);
        $statusCode = $response->getStatusCode();
        $content = $response->getContent();

        $this->assertEquals(404, $statusCode);
        // $this->assertEmpty($content);
    }
}
