<?php
// Reached only if the web server is misconfigured. Answer 404 so the directory
// does not advertise its own existence.
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "Not Found";
exit;
