# Tornado Engine Uploads

A [Tornado Engine](https://github.com/DylanBanta-WM/TornadoEngineCore) core module: upload files at /uploads or in Chat, and choose what Claude gets from each:

- **Filtered text** (the default): the text of the file (TXT, CSV, JSON, code; PDF, Word, Excel, PowerPoint; text in images by OCR), run through the broker.
- **Nothing**: for files with student information.
- **Image too**: images with no student information. If a face is found, the image is held back until the uploader confirms.

The originals stay in the website's `uploads` volume, which the harness can't see. The largest file size is set in Administration → Uploads (100 MB by default, up to 1024 MB).
