<?php

// Show all errors
// error_reporting(E_ALL); error_reporting(-1); ini_set('error_reporting', E_ALL); 

// Set time limit for PHP script
set_time_limit(60 * 2);

// SSE
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

// Log
function logExc($input, $cmd){
    // Get the current timestamp
    $timestamp = time();
    $dateFormat = "Y-m-d H:i:s";  // Adjust format as needed (YYYY-MM-DD HH:II:SS)
    $readableTime = date($dateFormat, $timestamp);

    // Log text
    $logText = "[$readableTime] SSE start\n";
    $logText .= "    Input Received: $input\n";
    $logText .= "    Command Executed: $cmd\n";

    // Open the file in append mode ("a")
    $logFile = fopen("event.custom_record", "a") or die("Unable to log!");

    // Write the text to the end of the file
    fwrite($logFile, $logText);

    // Close the file
    fclose($logFile);
}
 
// Send any buffered output to the browser
while (@ob_end_flush());

// flush
function flushSSE(){
    // Flush output buffers
    ob_flush();
    flush();
}

// End stream
$isEnd = false;
function endSSE($s = 0){
    // Signal stream end
    echo "event: close\n";
    echo "data:@END\n\n";
    flushSSE();
    // Close connection
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();  // For FastCGI (PHP-FPM)
    }else{
        ignore_user_abort(true); // Prevent errors if client disconnects prematurely
        header("Connection: close");
        header("Content-Length: 0");
    }
    // End Flush!
    ob_end_flush();
    flushSSE();
    // End PHP script!
    exit($s);
}

// Signal errors
$CMD_ERROR_INPUT = "Incorrect command input was used!";
function commandError($e){
    echo "data:@ERROR\n\n"; // Signal error
    echo "data:$e\n\n"; // Signal error
    endSSE();
}

// Process "cmd" query
$input = null;
$inputText = filter_input(INPUT_GET, 'cmd', FILTER_SANITIZE_STRING);
if(isset($inputText)){
    $input = preg_split('/\s+/', $inputText);
}else{
    endSSE();
}

// Command files!
$ENDERING = "~/endering.bash";

// Generate final shell command
$cmd;
// Process commands
// Blacklist: clean-records
$enderingAllowlist = array("help", "get", "commit", "rollback", "discard", "cache", "block", "unblock", "web", "clean");
if(in_array($input[0], $enderingAllowlist)){
    $cmd = $ENDERING." ".$input[0];
}

// Open shell
$descriptorspec = [
    0 => ["pipe", "r"],  // stdin (read)
    1 => ["pipe", "w"],  // stdout (write)
    2 => ["pipe", "w"]   // stderr (file to write to)
];
$process = null;
if(isset($cmd)){
    logExc($inputText, $cmd);
    $process =  proc_open($cmd." --color=always | cat", $descriptorspec, $pipes);
}else{
    echo "data:@UNKNOWN\n\n"; // Signal unknown command
    endSSE();
}

// Track shell output
if (is_resource($process)) {
    while ($s = fgets($pipes[1])) {
        echo "data: $s\n\n"; // Send output line by line as SSE events
        flushSSE();
        // usleep(10000); // 10ms cooldown
    }
    fclose($pipes[1]);
}

// Close shell
proc_close($process);

// End SSE
echo "data:@SUCCESS\n\n"; // Signal unknown command
endSSE();

?>