# AI Nutrition Scanner

This WordPress plugin allows scanning nutrition labels and parsing their contents using OCR and GPT.

Nutrition values are displayed with traffic-light colors (green/amber/red) based on UK-style thresholds so you can quickly see if sugars, fats or salt are high.

## Image processing

The browser now sends a resized but otherwise unmodified image to the `anp_scan`
endpoint. Earlier versions converted the photo to grayscale and applied a series
of filters before upload, which could make the OCR preview appear distorted.

## Manual Test: Oversized Image Rejection

1. Encode an image larger than 5 MB as a base64 data URI.
2. Submit the data to the plugin's `anp_scan` AJAX endpoint (e.g. via browser console or a custom form).
3. The request should fail and return an error message `Image exceeds 5 MB limit`.

This confirms that the plugin correctly rejects oversized images before attempting to save them.
