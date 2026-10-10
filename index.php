<?php
// Only reached if mod_rewrite is unavailable: send visitors to the public folder.
// (In that case set 'base_path' in config/config.php to end in /public.)
header('Location: public/');
