<?php

use PHPUnit\Framework\TestCase;

class GeminiClientTest extends TestCase {
    private $client;

    public function setUp(): void {
        parent::setUp();
        $this->client = new Gemini_Client();
        global $mock_responses;
        $mock_responses = [];
    }

    public function test_generate_content_success() {
        // Given: API returns a 200 OK response with correct JSON content.
        $expected_response = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['text' => 'Hello, I am Gemini!']
                        ]
                    ]
                ]
            ]
        ];
        global $mock_responses;
        $mock_responses = [
            'generateContent' => [
                'response' => ['code' => 200],
                'body' => json_encode($expected_response)
            ]
        ];

        // When: Calling generate_content.
        $response = $this->client->generate_content('test-store', 'Hi', 'Rules');

        // Then: The returned response should match the expected JSON.
        $this->assertSame($expected_response, $response);
    }

    public function test_generate_content_429_error() {
        // Given: API returns a 429 Too Many Requests response.
        global $mock_responses;
        $mock_responses = [
            'generateContent' => [
                'response' => ['code' => 429],
                'body' => 'Too Many Requests'
            ]
        ];

        // When: Calling generate_content.
        $response = $this->client->generate_content('test-store', 'Hi', 'Rules');

        // Then: The returned value should be a WP_Error with status 429.
        $this->assertTrue(is_wp_error($response), 'Should return WP_Error');
        $this->assertSame('api_error', $response->get_error_code());
        $data = $response->get_error_data();
        $this->assertSame(429, $data['status']);
    }

    public function test_generate_content_503_error() {
        // Given: API returns a 503 Service Unavailable response.
        global $mock_responses;
        $mock_responses = [
            'generateContent' => [
                'response' => ['code' => 503],
                'body' => 'Service Unavailable'
            ]
        ];

        // When: Calling generate_content.
        $response = $this->client->generate_content('test-store', 'Hi', 'Rules');

        // Then: The returned value should be a WP_Error with status 503.
        $this->assertTrue(is_wp_error($response), 'Should return WP_Error');
        $this->assertSame('api_error', $response->get_error_code());
        $data = $response->get_error_data();
        $this->assertSame(503, $data['status']);
    }

    public function test_generate_content_400_error() {
        // Given: API returns a 400 Bad Request response.
        global $mock_responses;
        $mock_responses = [
            'generateContent' => [
                'response' => ['code' => 400],
                'body' => 'Bad Request'
            ]
        ];

        // When: Calling generate_content.
        $response = $this->client->generate_content('test-store', 'Hi', 'Rules');

        // Then: The returned value should be a WP_Error with status 400.
        $this->assertTrue(is_wp_error($response), 'Should return WP_Error');
        $this->assertSame('api_error', $response->get_error_code());
        $data = $response->get_error_data();
        $this->assertSame(400, $data['status']);
    }

    public function test_generate_content_network_error() {
        // Given: wp_remote_request returns a WP_Error directly (e.g., DNS error).
        global $mock_responses;
        $mock_responses = [
            'generateContent' => new WP_Error('http_request_failed', 'Network down')
        ];

        // When: Calling generate_content.
        $response = $this->client->generate_content('test-store', 'Hi', 'Rules');

        // Then: The returned value should be the same WP_Error.
        $this->assertTrue(is_wp_error($response), 'Should return WP_Error');
        $this->assertSame('http_request_failed', $response->get_error_code());
    }
}
