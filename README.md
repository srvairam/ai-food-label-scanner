# AI Nutrition Scanner

This WordPress plugin allows scanning nutrition labels and parsing their contents using OCR and GPT.

## Manual Test: Oversized Image Rejection

1. Encode an image larger than 5 MB as a base64 data URI.
2. Submit the data to the plugin's `anp_scan` AJAX endpoint (e.g. via browser console or a custom form).
3. The request should fail and return an error message `Image exceeds 5 MB limit`.

This confirms that the plugin correctly rejects oversized images before attempting to save them.
