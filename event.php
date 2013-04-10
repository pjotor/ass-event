<?php
define('IN_PHPBB', true);
$phpbb_root_path = (defined('PHPBB_ROOT_PATH')) ? PHPBB_ROOT_PATH : './';
$phpEx = substr(strrchr(__FILE__, '.'), 1);
include($phpbb_root_path . 'common.' . $phpEx);
include($phpbb_root_path . 'includes/functions_display.' . $phpEx);

// Start session management
$user->session_begin();
$auth->acl($user->data);

//The different user types in phpBB
/*
define('USER_NORMAL', 0);
define('USER_INACTIVE', 1);
define('USER_IGNORE', 2);
define('USER_FOUNDER', 3);
*/

//The event-page uses a table looking like this
/*
CREATE TABLE IF NOT EXISTS `events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(140) COLLATE utf8_bin NOT NULL,
  `user_id` int(11) NOT NULL,
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated` timestamp NULL DEFAULT NULL,
  `inactive` tinyint(1) NOT NULL DEFAULT '0',
  `date` date NOT NULL,
  `location` varchar(50) COLLATE utf8_bin NOT NULL,
  `forum_url` varchar(240) COLLATE utf8_bin NOT NULL,
  `desc` varchar(300) COLLATE utf8_bin NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COLLATE=utf8_bin AUTO_INCREMENT=6 ;
*/

// iCal class from http://jamiebicknell.tumblr.com/post/413492676/ics-generator-php-class
class ICS {
    var $data;
    var $name;
    function ICS($start,$end,$name,$description,$location) {
        $this->name = $name;
        $this->data = "BEGIN:VCALENDAR\nVERSION:2.0\nMETHOD:PUBLISH\nBEGIN:VEVENT\nDTSTART:".date("Ymd\THis\Z",strtotime($start))."\nDTEND:".date("Ymd\THis\Z",strtotime($end))."\nLOCATION:".$location."\nTRANSP: OPAQUE\nSEQUENCE:0\nUID:\nDTSTAMP:".date("Ymd\THis\Z")."\nSUMMARY:".$name."\nDESCRIPTION:".$description."\nPRIORITY:1\nCLASS:PUBLIC\nBEGIN:VALARM\nTRIGGER:-PT10080M\nACTION:DISPLAY\nDESCRIPTION:Reminder\nEnd:VALARM\nEnd:VEVENT\nEnd:VCALENDAR\n";
    }
    function save() {
        file_put_contents($this->name.".ics",$this->data);
    }
    function show() {
        header("Content-type:text/calendar");
        header('Content-Disposition: attachment; filename="'.$this->name.'.ics"');
        Header('Content-Length: '.strlen($this->data));
        Header('Connection: close');
        echo $this->data;
    }
}

//Setup defaults for these flags
$adminMod = false;
$canAdd = false;
//Change this to wherever you have the table
$eventsTable = "`forum_test`.`events`";

// Set admin/loggedin 
if ($auth->acl_gets('a_', 'm_')) {
	$adminMod = true;
	$canAdd = true;
} else {
//all logged in and active can add
	$canAddEvent = array(0,3);
	if( in_array($user->data['user_type'], $canAddEvent) ) {
		$canAdd = true;
	}
}

//Get posts and filter
$name = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING);
$location = filter_input(INPUT_POST, 'location', FILTER_SANITIZE_STRING);
$forum = filter_input(INPUT_POST, 'thread', FILTER_SANITIZE_URL);
$desc = filter_input(INPUT_POST, 'desc', FILTER_SANITIZE_STRING);
$date = strtotime($_POST["date"]);
$mysqldate = date( 'Y-m-d H:i:s', $date );
$event_id = filter_input(INPUT_POST, 'id', FILTER_SANITIZE_NUMBER_INT);

//iCal
if(isset($_GET["ical"])){
	$event_id = filter_input(INPUT_GET, 'ical', FILTER_SANITIZE_NUMBER_INT);
	
	$sql = 'SELECT e.name, e.location, e.date, e.desc 
		FROM ' . $eventsTable . ' e 
		WHERE e.inactive = 0 AND e.id = ' . $event_id . '
		ORDER BY e.date ASC';
		
	$result = $db->sql_query($sql);
	while ($row = $db->sql_fetchrow($result)) {
		$event = new ICS("{$row['date']} 00:00","{$row['date']} 24:00",$row['name'],$row['desc'],$row['location']);
	}
	$db->sql_freeresult($result);
	$event->show();
	die();
}
//Update
if( $event_id > 0 && $canAdd ) {
	
	$sql = "UPDATE $eventsTable 
		SET 
		`name` = '$name', `date` = '$mysqldate', `location` = '$location', 
		`forum_url` = '$forum', `desc` = '$desc', `updated` =  CURRENT_TIMESTAMP 
		WHERE `events`.`id` = $event_id AND `events`.`user_id` = {$user->data['user_id']};";
		
	$result = $db->sql_query($sql);
	$db->sql_freeresult($result);	
}
//Add
if( $event_id < 1 && isset($_POST["name"]) && $canAdd ) {
	$sql = "INSERT INTO $eventsTable 
	(`name`, `user_id`, `updated`, `date`, `location`, `forum_url`, `desc`) 
	VALUES 
	('$name', {$user->data['user_id']}, CURRENT_TIMESTAMP, '$mysqldate', '$location', '$forum', '$desc');";
	
	$result = $db->sql_query($sql);
	$db->sql_freeresult($result);
}
//Edit
if( isset($_GET["edit"]) && $canAdd ) {
	$admin = $adminMod ? 1 : 0;
	$event_id = filter_input(INPUT_GET, 'edit', FILTER_SANITIZE_NUMBER_INT);
	
	$sql = "SELECT `id`, `name`, `user_id`, `updated`, `date`, `location`, `forum_url`, `desc`
		FROM $eventsTable WHERE id = $event_id AND (user_id = {$user->data['user_id']} OR 1 = $admin);";
	
	//Set some default values
	$edit_event = array(
		"id" => '',
		"name" => '',
		"forum_url" => '',
		"location" => '',
		"date" => '',
		"edit" => false
	);
		
	$result = $db->sql_query($sql);
	while ($row = $db->sql_fetchrow($result)) {
		$edit_event = array(
			"id" => $row['id'],
			"name" => $row['name'],
			"forum_url" => $row['forum_url'],
			"location" => $row['location'],
			"date" => $row['date'],
			"description" => $row['desc'], 
			"edit" => ($user->data['user_id'] == $row['user_id'] || $adminMod)
		);	
		
	}	
	$db->sql_freeresult($result);
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta http-equiv="content-type" content="text/html; charset=UTF-8">
  <title></title>

  <!-- 
  The bootstrap stuff is not realy working...
  //-->
  <link rel="stylesheet" type="text/css" href="http://twitter.github.io/bootstrap/1.4.0/bootstrap.min.css">
  
  <script type='text/javascript' src="http://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/2.1.0/bootstrap.min.js"></script>
 
  <link rel="stylesheet" type="text/css" href="http://code.jquery.com/ui/1.9.2/themes/base/jquery-ui.css">  
  <script type='text/javascript' src='//code.jquery.com/jquery-1.9.1.js'></script>
  <script type="text/javascript" src="http://code.jquery.com/ui/1.9.2/jquery-ui.js"></script>  

  <style type='text/css'>
    #map { width: 600px; height: 500px; }
  </style>
  
</head>
<body>

	<!-- New/Edit event -->
	<div id="event">
		<form class="form-horizontal" method="POST" action="event.php" name="event_form" id="event_form">
		<fieldset>

		<!-- Form Name -->
		<legend>Nytt event</legend>

		<!-- Text input-->
		<div class="control-group">
		  <label class="control-label">Namn</label>
		  <div class="controls">
			<input id="name" name="name" type="text" placeholder="Namnet på eventet" class="input-xlarge" required="" value="<?= $edit_event["name"] ?>">
			<input id="id" name="id" type="hidden" value="<?= $edit_event["id"] ?>">
			<p class="help-block">Det här är vad som kommer synas i listorna.</p>
		  </div>
		</div>

		<!-- Text input-->
		<div class="control-group">
		  <label class="control-label">Datum</label>
		  <div class="controls">
			<input id="date" name="date" type="date" placeholder="ÅÅÅÅ-MM-DD" class="input-xlarge" required="" value="<?= $edit_event["date"] ?>">
			<p class="help-block">När går eventet av stapeln?</p>
		  </div>
		</div>

		<!-- Button Drop Down -->
		<div class="control-group">
		  <label class="control-label">Plats</label>
		  <div class="controls">
			<div class="input-append">
			  <input id="location" name="location" class="span2" placeholder="använd knappen nedan" type="text" required="" value="<?= $edit_event["location"] ?>">
			  <div class="btn-group">
				<button class="btn dropdown-toggle" data-toggle="dropdown" id="showMap">
				  Hämta från karta
				</button>
				<ul class="dropdown-menu">
				</ul>
			  </div>
			</div>
		  </div>
		</div>

		<!-- Text input-->
		<div class="control-group">
		  <label class="control-label">Forumtråd</label>
		  <div class="controls">
			<input id="thread" name="thread" type="text" placeholder="http://" class="input-xlarge" required="" value="<?= $edit_event["forum_url"] ?>">
			<p class="help-block">Klistra in en länk till forumtrådet för eventet.</p>
		  </div>
		</div>

		<!-- Textarea -->
		<div class="control-group">
		  <label class="control-label">Beskriving</label>
		  <div class="controls">                     
			<textarea id="desc" name="desc"><?= $edit_event["description"] ?></textarea>
		  </div>
		</div>

		<!-- File Button 
		<div class="control-group">
		  <label class="control-label">Flyer/Bild</label>
		  <div class="controls">
			<input id="image" name="image" class="input-file" type="file">
		  </div>
		</div>
		--> 

		<!-- Button (Double) -->
		<div class="control-group">
		  <label class="control-label"></label>
		  <div class="controls">
			<button id="save" name="save" class="btn btn-success">Spara!</button>
			<button class="btn btn-danger" type="reset">Reset</button>
		  </div>
		</div>

		</fieldset>
		</form>
	</div>

  <div id="map"></div>
  
 <?php 
	//Determine if the user can add events, duh...
	if($canAdd) {
?>	
	<button id="new">
	  Lägg till nytt event
	</button>
<?php } ?>				

<?php

/*
	These should be presented in a better way
*/

//Show a list of active events in the future
$sql = 'SELECT id, name, location, date, forum_url, user_id
	FROM ' . $eventsTable . '
	WHERE inactive = 0 AND date > CURRENT_TIMESTAMP	
	ORDER BY date ASC';

$result = $db->sql_query($sql);

$events = array();
echo "<table>\n";
echo "<tr><th>Datum</th><th>Namn</th><th>Länk</th><th>Plats</th><th>iCal</th><th></th></tr>\n";
while ($row = $db->sql_fetchrow($result)) {
	echo "\t<tr>\n";
	echo "\t<td>" . $row['date'] . "</td>\n";
	echo "\t<td><a href='?event=" . $row['id'] . "'>" . $row['name'] . "</a></td>\n";
	echo "\t<td><a href='" . $row['forum_url'] . "'>Forumtråd</a></td>\n";
	echo "\t<td><button data-location='" . $row['location']. "' class='loc'>Visa på karta</button></td>\n";
	echo "\t<td><a href='?ical=" . $row['id'] . "'>iCal</a></td>\n";
	if($user->data['user_id'] == $row['user_id'] || $adminMod)
		echo "\t<td><a href='?edit=" . $row['id'] . "'>Uppdatera</a></td>\n";
	echo "\t</tr>\n";
		
	$events["event" . $row['id']] = array(
		"id" => $row['id'],
		"name" => $row['name'],
		"forum_url" => $row['forum_url'],
		"location" => $row['location'],
		"date" => $row['date'],
		"edit" => ($user->data['user_type'] == $row['user_id'] || $adminMod)
	);	
}
echo "</table>\n";

$db->sql_freeresult($result);

echo "\n<hr />\n";

//Set up a JSON-object with all events in the list
echo "<script>\n";
echo "var marker_points = " .  json_encode($events) . ";\n";
echo "</script>\n";
//echo "<button id='all_map'>Visa karta</button>\n";

echo "\n<hr />\n";

//Show a specific event
$event_id = filter_input(INPUT_GET, 'event', FILTER_SANITIZE_NUMBER_INT);

if( $event_id ) {

	$sql = 'SELECT e.id, name, e.location, e.date, e.updated,  e.desc, e.forum_url, e.user_id, u.username 
		FROM ' . $eventsTable . ' e 
		LEFT JOIN ' . USERS_TABLE . ' u ON (u.user_id = e.user_id) 
		WHERE e.inactive = 0 AND e.id = ' . $event_id . '
		ORDER BY e.date ASC';

		
	$result = $db->sql_query($sql);
	while ($row = $db->sql_fetchrow($result)) {

		echo "<h2>" .  $row['name'] . "</h2>\n";
		echo "<strong>" .  $row['date'] . "</strong>\n";
		echo "<button data-location='" . $row['location'] . "' class='loc'>Visa på karta</button>\n";
		echo "<a href='" . $row['forum_url'] . "'>Forumtråd</a>\n";
		echo '<a href="' . append_sid("{$phpbb_root_path}memberlist.$phpEx", 
			'mode=viewprofile&amp;u=' . 
			$row['user_id']) . '">' . $row['username'] . '</a>';
		echo "<hr/>\n";
		echo "<p>" . $row['desc'] . "</p>\n";
		if( !is_null($row['updated']) ) echo "<em>uppdaterad: " . $row['updated'] . "</em>";

	
	}
	$db->sql_freeresult($result);
}

?>				


<script type='text/javascript'>//<![CDATA[ 
$(window).load(function(){
	
	$("#map, #event").hide();

	//The map dialog
	$( "#map" ).dialog({
	autoOpen: false,
	minHeight: 500, 
	minWidth: 500
	});
	
	//Show map when adding event
	$( "#showMap" ).click(function() {
		$( "#map" )
			.dialog("option", "open", function(){
				google.maps.event.trigger(map, 'resize');
				map.setZoom(4);
				map.setCenter(new google.maps.LatLng(61.03701211560139, 15.292968374999969));		
				marker.setVisible(false);
			})
			.dialog("option", "buttons", [ 
				{ 
					text: "Sätt spelplats", 
					click: function() { 
						$("#location").val(marker.getPosition().toString().replace(/[()]/ig,""));
						$( this ).dialog( "close" ); 
					} 
				},
				{ 
					text: "Avbryt", 
					click: function() { 
						$( this ).dialog( "close" ); 
					} 
				} 		
			])
			.dialog( "open" );
	});	
	
	//Auto open event if you can and want to edit it
	$( "#event" ).dialog({ 
		autoOpen:  <?= (isset($_GET["edit"]) && $edit_event["edit"]) ? "true" : "false" ?>,
		minHeight: 600, 
		minWidth: 500
	});
	
	$( "#new" ).click(function() {
		$( "#event" ).dialog( "open" );
	});		
	
	//Show location of event
	$(".loc").click(function(){
		if(!map) {
			alert("Kartan gick inte att ladda.");
			return;
		}
		var point = $(this).data("location").split(", ");
		
		if(point.length !== 2) {
			alert("Kartan gick inte att ladda.");
			return;
		}
		
		point = new google.maps.LatLng(point[0], point[1]);
		google.maps.event.trigger(map, 'resize');
		marker.setPosition(point);
		marker.setVisible(true);


		
		$( "#map" )
			.dialog({
				open: function(){
					google.maps.event.trigger(map, 'resize');
					map.setZoom(8);
					map.setCenter(point);					
				},
				buttons: [],
			})			
			.dialog( "open" );		
		
	});
	
	//Show all events on map - TODO
	$("#all_map").click(function(){
		var point, latlng, mark;
		var bounds=new google.maps.LatLngBounds();
		var allPoints = [];
		
		marker.setVisible(false);
		
		$.each(marker_points, function(i,n){
			point = n.location.split(", ");
			latlng = new google.maps.LatLng(point[0], point[1]); 

			allPoints.push(
				new google.maps.Marker({
					map: map,
					position: latlng,
					title:n.name,
					clickable: true
				})
			)	
			
			var mark = allPoints[allPoints.length-1]
			mark.id = n.id;
			
			google.maps.event.addListener(mark, 'click', function () {
				console.info(this.id);
			});		
			
			bounds.extend(latlng);
		});	  
		
		$( "#map" )
			.dialog({
				open: function(){
					google.maps.event.trigger(map, 'resize');
				},
				buttons: [],
			})			
			.dialog( "open" );			
	});
});

//Google map init load/init functions
var marker;
var map = false;

function initialize() {
  var mapOptions = {
	zoom: 5,
	center: new google.maps.LatLng(61.03701211560139, 15.292968374999969),
	mapTypeId: google.maps.MapTypeId.HYBRID
  }
  map = new google.maps.Map(document.getElementById("map"), mapOptions);

  marker = new google.maps.Marker({
	position: map.getCenter(),
	map: map,
	title: 'Eventplats'
  });  
  
  marker.setVisible(false);
  
  google.maps.event.addListener(map, 'click', function(event) {
	marker.setPosition(event.latLng);
	if(!marker.getVisible()) marker.setVisible(true);
  });  
}

function loadMap(){
	if(!map) {
	  var script = document.createElement("script");
	  script.type = "text/javascript";
	  script.src = "http://maps.google.com/maps/api/js?sensor=false&callback=initialize";
	  document.body.appendChild(script);	
	}
}

window.onload = loadMap;

//]]></script>
</body>


</html>
