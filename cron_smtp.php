<?php

/*

  SWIFT MAILER REQUIRED
  ---------------------

  sudo apt-get install php-pear
  sudo pear channel-discover pear.swiftmailer.org
  sudo pear install swift/swift

*/

define('EMONCMS_EXEC', 1);

chdir("/var/www/emoncms");

require "process_settings.php";
$mysqli = @new mysqli($server,$username,$password,$database);

$redis = new Redis();
$redis->connect("127.0.0.1");

// 1) Setup swift mailer transport 
require_once 'swift_required.php';

// Using the Mail Transport 
$transport = Swift_SmtpTransport::newInstance('mail.yourserver.com', 26)
  ->setUsername('username')
  ->setPassword('password')
;

// Create the Mailer using your created Transport
$mailer = Swift_Mailer::newInstance($transport);

// 2) Determine feeds
$now = time();
$h24 = $now - (3600*2);
$h48 = $now - (3600*4);
 
$keys = $redis->keys("feed:lastvalue:*");

$users = array();

foreach ($keys as $key)
{
  $parts = explode(":",$key);
  $feedid = $parts[2];
  $time = strtotime($redis->hget("feed:lastvalue:$feedid","time"));
  
  if ($time>=$h48 && $time<=$h24)
  {
    // fetch userid
    
    $result = $mysqli->query("SELECT name, userid FROM feeds WHERE `id`='$feedid'");
    $row = $result->fetch_array();
    $userid = $row['userid'];
    if (!isset($users[$row['userid']])) $users[$userid] = array('id'=>$userid, 'feeds'=>array());
    $users[$userid]['feeds'][] = $row['name'];
  }
}

foreach ($users as $user)
{

  $userid = $user['id'];
  $result = $mysqli->query("SELECT email FROM notify WHERE `userid` = '$userid' AND `enabled` = '1';");
  
  
  if ($result && $result->num_rows)
  {  
    $row = $result->fetch_array();
    $email = $row['email'];
    
    // Send an email
    
    $body = "<p><b>Hello!</b></p><p>The following emoncms feeds have become inactive for more than 2 hours:</p><ul>";
    foreach ($user['feeds'] as $feed)
    {
      $body .= "<li>".$feed."</li>";
    }
    $body .= "</ul>";
    $body .= "<p><i>This is an automated email generated by the emoncms notify module. To turn 'notify on inactive' off click on disable on the notify module page.</i></p>";
    
    // Create the message
    $message = Swift_Message::newInstance()

      // Give the message a subject
      ->setSubject(count($user['feeds'])."emoncms feeds have become inactive")

      // Set the From address with an associative array
      ->setFrom(array('from@address' => 'emoncms'))

      // Set the To addresses with an associative array
      ->setTo(array($email))

      // Give it a body
      ->setBody($body, 'text/html')

    ;
    $result = $mailer->send($message);
  }
}



