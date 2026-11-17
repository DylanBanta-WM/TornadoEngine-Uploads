# Tornado Engine Uploads

A [Tornado Engine](https://github.com/DylanBanta-WM/TornadoEngineCore) core module that other modules use for file uploads (for example Chat's message box). It has no page of its own.

When someone uploads a file, they say whether it contains student information. Claude gets the file's text (TXT, CSV, JSON, code; PDF, Word, Excel, PowerPoint; text in images by OCR) run through the broker. Images without student information are given to Claude too; if a face is found, the image waits until the uploader confirms.

The originals stay in the website's `uploads` volume, which the harness can't see. The largest file size is set in Administration → Uploads (100 MB by default, up to 1024 MB).

For modules: POST files to `route('uploads.store')` (`files[]`, and `share`: `text` for files with student information, `image` for images without), then use `Upload::forClaude()` or `describeForClaude()` for what Claude can read.
