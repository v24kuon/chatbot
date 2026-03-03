# Test Perspectives: Gemini_Client::generate_content

| Case ID | Input / Precondition | Perspective (Equivalence / Boundary) | Expected Result | Notes |
|---------|----------------------|---------------------------------------|-----------------|-------|
| TC-G-01 | Valid parameters | Equivalence - normal | Returns decoded JSON response | Happy path |
| TC-G-02 | API returns 429 | Equivalence - error | Returns `WP_Error` with status 429 | Rate limit handling |
| TC-G-03 | API returns 503 | Equivalence - error | Returns `WP_Error` with status 503 | Service unavailable handling |
| TC-G-04 | API returns 400 | Equivalence - error | Returns `WP_Error` with status 400 | Bad request handling |
| TC-G-05 | Network error | Equivalence - error | Returns `WP_Error` from `wp_remote_request` | Network failure handling |
