<?php
include("NexmoMessage.php");
$sms = new NexmoMessage(getenv('key'), getenv('secret'));

if ($sms->inboundText()) {
	// database details
	$con = mysql_connect(getenv('MYSQL_DB_HOST'), getenv('MYSQL_USERNAME'), getenv('MYSQL_PASSWORD'));
	if (!$con) { die('Could not connect: ' . mysql_error()); }
	mysql_select_db(getenv('MYSQL_DB_NAME'), $con);

	$text = trim($sms->text);
	
	// new poll
	if (strcasecmp($text, 'ask') == 0) {
		// Create new poll and text user
		function createPoll($sms) {
			mysql_query("INSERT INTO Poll (phone, isActive) VALUES ('$sms->from', 1)");
			$id = mysql_insert_id();
			$sms->reply("You've asked a new poll! Tell others to text '$id [letter of choice]' to 305-222-7004 to vote. Reply 'TALLY $id' to stop this poll and get results.");
		}

		// Find this user
		$result = mysql_query("SELECT remaining FROM Person WHERE phone = $sms->from");
		if (mysql_num_rows($result) == 0) {
			// This is a new, unrecognized user. Create a user account for them.
    		mysql_query("INSERT INTO Person (phone, remaining) VALUES ('$sms->from', 49)");
    		createPoll($sms);
		} else {
			// User found
			$row = mysql_fetch_assoc($result);
			$remaining = $row['remaining'];
			//if ($remaining > 0) {
				// This user has polls remaining, decrement their remaining poll count
				$remaining = $remaining - 1;
				mysql_query("UPDATE Person SET remaining = $remaining WHERE phone = $sms->from");
				createPoll($sms);
			//}
			/*else
				// User is out of polls, ask them to pay to buy more
				$sms->reply('Sorry, you seem to be out of polls! Please pay for more yo...');
			*/
		}
	}
	
	// stop existing poll
	else if (strncasecmp($text, 'tally', 5) == 0) {
		$code = trim(substr($text, 5));
		$result = mysql_query("SELECT * FROM Poll WHERE id = $code AND phone = $sms->from AND isActive = 1");
		if (mysql_num_rows($result) == 0) {
    		$sms->reply('Sorry that poll code was not recognized, or may not be active anymore.');
		} else {
			$row = mysql_fetch_assoc($result);
			// set poll to not be active anymore
			mysql_query("UPDATE Poll SET isActive = 0 WHERE id = $code");
			
			// fetch all answers for this poll
			$answers = mysql_query("SELECT * FROM Answer WHERE pollid = $code");
			if (mysql_num_rows($answers) == 0) {
				$sms->reply('Your poll has been stopped. Unfortunately, there were no responses.');
			} else {
				// compile votes to generate distribution
				$responses = array();
				while ($row2 = mysql_fetch_assoc($answers)) {
					$r = $responses[$row2['response']];
					if (isset($r)) {
						$responses[$row2['response']] = $r + 1;
					} else
						$responses[$row2['response']] = 1;
				}
				// sort distribution by key
				ksort($responses);
				// concat distributions into list
				$reply = "Here are your results:";
				foreach($responses as $index => $count) {
					$option = chr($index + 65);
					$reply = $reply . " $option: $count,";
				}
				// remove last ending comma
				$reply = substr($reply, 0, -1);
				// send reply
				$sms->reply($reply);
			}
		}
	}
	
	// answer a poll
	else {
		// record an answer to a quiz		
		preg_match('/(\d+)\W*([A-Z])/i', $text, $matches);
		$id = $matches[1];
		
		// Check if this poll is still active
		$poll = mysql_query("SELECT * FROM Poll WHERE id = $id AND isActive = 1");
		if (mysql_num_rows($poll) == 1) {
			// make the letter uppercase, convert it to charcode
			$letter = strtoupper($matches[2]);
			$option = ord($letter) - 65;
			
			// check if this user answered this poll already
			$result = mysql_query("SELECT * FROM Answer WHERE pollid = $id AND phone = $sms->from");
			if (mysql_num_rows($result) == 0)
				// This is a new answer. Save it.
				mysql_query("INSERT INTO Answer (pollid, response, phone) VALUES ($id, $option, '$sms->from')");
			else
				// Previous answer found, so let's just update it
				mysql_query("UPDATE Answer SET response = $option WHERE pollid = $id AND phone = $sms->from");
		}
	}
	
	mysql_close($con);
	
	header('HTTP/1.0 200 OK', true, 200);
}
?>

HTTP/1.0 200 OK
