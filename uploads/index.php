<?php
// Directory index placeholder: reaching this means the web server allowed a
// directory listing or a direct index hit. Both are refused.
http_response_code(403);
header('Content-Type: text/plain; charset=utf-8');
echo 'Forbidden';
exit;
