<?php
session_start();
require_once('config.php');

// Tune PHP
libxml_use_internal_errors(true);
ini_set('allow_url_fopen', 'On');
mb_internal_encoding("UTF-8");

// Logout
if (isset($_REQUEST['logout'])) {
	unset($_SESSION['u_login']);
	session_destroy();
	header("Location: " . $_SERVER['SCRIPT_NAME']);
	die();
}

// Check authorization
if (!isset($_POST['passwd']) and !empty($_POST) and !isset($_SESSION['u_login'])) {
	die('Access denied!');
}

// Open database
try {
	$DB = new PDO(DB_LINK, DB_USER, DB_PASS);
	$DB->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$DB->exec("set names utf8");
} catch (PDOException $e) {
	die("dbError: " . $e->getMessage() . "\n");
}

// Check sqlite DB is proper
if ($DB->getAttribute(PDO::ATTR_DRIVER_NAME) == 'sqlite') {
	preg_match_all('/^\w+:(.+)$/',DB_LINK,$matches);
	if (!file_exists($matches[1][0]))
		die('dbError: Database file <strong>'.$matches[1][0].'</strong> is not found!');
	if (!is_writable($matches[1][0]))
		die('dbError: Database file <strong>'.$matches[1][0].'</strong> is not writable!');
}

// Prepare write to log
try {
$query_log = $DB->prepare("INSERT INTO log (id, time, text) VALUES (?, ?, ?)");
} catch (PDOException $e) {
	die("dbError: " . $e->getMessage() . "\n");
}

// Read settings from DB
try {
	$query_settings = $DB->query("SELECT * FROM settings");
	$settings_result = $query_settings->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
	die("dbError: " . $e->getMessage() . "\n");
}
$settings = $settings_result[0];
setlocale(LC_ALL, $settings['locale']);

// Check password
if (isset($_POST['passwd'])) {
	if (sha1($_POST['passwd']) == $settings['admin_pass']) {
		$_SESSION['u_login'] = 'YES';
		$query_log->execute(array('access', time(), "Admin access granted from " . $_SERVER['REMOTE_ADDR']));
	} else {
		unset($_SESSION['u_login']);
		session_destroy();
		$query_log->execute(array('denied', time(), "Wrong admin password from " . $_SERVER['REMOTE_ADDR']));
	}
}

// Feed request
if (!empty($_REQUEST['feed'])) {
	$name = strtolower(trim($_REQUEST['feed']));
	$result = '';
	try {
		if (is_numeric($name)) {
			$query = $DB->prepare("SELECT * FROM feeds WHERE id = :id AND enabled > 0");
			$query->bindParam(':id', $name, PDO::PARAM_INT);
		} else {
			$query = $DB->prepare("SELECT * FROM feeds WHERE name = :name AND enabled > 0");
			$query->bindParam(':name', $name, PDO::PARAM_STR);
		}
		$query->execute();
		$result = $query->fetchAll(PDO::FETCH_ASSOC);
		$query->closeCursor();
	} catch (PDOException $e) {
		die_error($name, 'dbError: ' . $e->getMessage());
	}
	if (count($result) == 0) {
		die_error($name, '[ERROR] RSS feed <strong>' . $name . '</strong> not found!');
	} else {
		$result = $result[0];
		$xml = $result['xml'];
		$rss = simplexml_load_file($result['url']);
		if ($rss !== false) {
			$pubDate = strtotime($rss->channel->pubDate);
			if ($pubDate !== false) {
				// if pubDate is more then last update
				$need_update = ($pubDate > $result['lastupdate']) ? true : false;
			} else {
				// if pubDate in feed isn't specified then update after 30 min
				$need_update = (time() - $result['lastupdate'] > 1800) ? true : false;
			}
			if ($need_update || isset($_REQUEST['force'])) {
				$id = $result['id'];
				$filters = preg_split("/\r\n|\n|\r/", $result['filter']);
				$forced = (isset($_REQUEST['force'])) ? 'forced ' : '';
				$query_log->execute(array($id, time(), 'Start '.$forced.'update feed <strong>' . $name . '</strong>'));
				foreach ($rss->channel->item as $item) {
					set_time_limit(60);
					$content = '';
					if (function_exists('curl_init')) {
						$curl = curl_init($item->link);
						curl_setopt($curl, CURLOPT_ENCODING, "");
						curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
						curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
						$html = curl_exec($curl);
						curl_close($curl);
					} else {
						$html = file_get_contents($item->link);
					}
					// Convert HTML encoding to UTF-8
					if ($result['charset'] !== 'UTF-8')
						$html = mb_convert_encoding($html, 'UTF-8', $result['charset']);
					// Choice of a method of extraction of a full content
					switch ($result['method']) {
						case "Readability":
							require_once 'libs/Readability.php';
							$readability = new Readability($html, $item->link);
							$resinit = $readability->init();
							if ($resinit) {
								$content = $readability->getContent()->innerHTML;
							} else {
								$query_log->execute(array($id, time(), 'ERROR get full text from ' . $item->link . ' for <strong>' . $name . '</strong>'));
							}
							break;
						case "Simple HTML DOM":
							if (!empty($result['method_detail'])) {
								require_once 'libs/simple_html_dom.php';
								$html_dom = str_get_html($html);
								if ($html_dom) {
									$ret = $html_dom->find($result['method_detail']);
									if (count($ret) > 0) {
										foreach ($ret as $el) {
											$content .= $el->innertext;
										}
									}
									$html_dom->clear();
								} else {
									$query_log->execute(array($id, time(), 'ERROR get full text from ' . $item->link . ' for <strong>' . $name . '</strong>'));
								}
							}
							break;
					}
					if (!empty($content)) {
						// Remove unnecessary strings
						if (count($filters) > 0) {
							foreach ($filters as $search) {
								$search = trim($search);
								if (preg_match('/^\/.+\/[a-z]*/i', $search)) {
									// If filter is regex
									$content = mb_ereg_replace($search, '', $content);
								} else {
									// If filter is replace string
									$content = str_replace($search, '', $content);
								}
							}
						}
						// Remove blank lines
						$content = preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "", $content);
						$item->description = '<![CDATA[' . trim($content) . ']]>';
					}
					if (isset($_REQUEST['preview'])) {
						echo '<div class="row" style="padding: 20px;">';
						echo $content;
						echo '</div>';
						die();
					}
				}
				$xml = html_entity_decode($rss->asXML(), ENT_XML1);
				$time = ($pubDate !== false) ? $pubDate : time();
				try {
					$query_update = $DB->prepare("UPDATE feeds SET xml = :xml, lastupdate = :lastupdate WHERE id = :id");
					$query_update->bindParam(':xml', $xml, PDO::PARAM_STR);
					$query_update->bindParam(':lastupdate', $time, PDO::PARAM_INT);
					$query_update->bindParam(':id', $id, PDO::PARAM_INT);
					$query_update->execute();
					$query_log->execute(array($id, time(), 'Finish update feed <strong>' . $name . '</strong>'));
				} catch (PDOException $e) {
					die_error($id, 'dbError: ' . $e->getMessage());
				}
			}
		} else {
			$query_log->execute(array($result['id'], time(), "ERROR loading RSS feed from " . $result['url']));
		}
		$query_log->execute(array($result['id'], time(), "Return feed <strong>" . $name . "</strong> for " . $_SERVER['REMOTE_ADDR']));
		header('Content-Type: text/xml; charset=utf-8');
		die($xml);
	}
}

// Return log of feed
if (isset($_POST['log'])) {
	try {
		if ($_POST['log'] > 0) {
			$query = $DB->prepare("SELECT * FROM log WHERE id = :id");
			$query->bindParam(':id', $_POST['log'], PDO::PARAM_INT);
		} else {
			$query = $DB->prepare("SELECT * FROM log");
		}
		$query->execute();
		$result = $query->fetchAll(PDO::FETCH_ASSOC);
		if (count($result) == 0) {
			die('[ERROR] Log for RSS feed not found!');
		} else {
			?>
			<div style="overflow:auto; height: 500px;">
				<table id="log" class="table table-striped table-condensed table-hover">
					<thead>
					<tr>
						<th width="10">id</th>
						<th width="30">Date</th>
						<th width="30">Time</th>
						<th>Text</th>
					</thead>
					<tbody> <?php
					foreach ($result as $row) {
						echo '<tr>';
						echo '<td>' . $row['id'] . '</td>';
						echo '<td>' . date('d.m.Y', $row['time']) . '</td>';
						echo '<td>' . date('H:i:s', $row['time']) . '</td>';
						echo '<td>' . $row['text'] . '</td>';
						echo '</tr>';
					}
					?>
					</tbody>
				</table>
			</div>
		<?php
		}
		die();
	} catch (PDOException $e) {
		die_error($_POST['log'], 'dbError: ' . $e->getMessage());
	}
}

// Return XML of feed
if (!empty($_POST['xml'])) {
	try {
		$query = $DB->prepare("SELECT xml FROM feeds WHERE id = :id");
		$query->bindParam(':id', $_POST['xml'], PDO::PARAM_INT);
		$query->execute();
		$result = $query->fetchAll(PDO::FETCH_ASSOC);
		if (count($result) == 0) {
			die('[ERROR] XML for RSS feed not found!');
		} else {
			echo '<textarea class="form-control" style="overflow:auto;resize:none" wrap="off" rows="25" readonly>' . $result[0]['xml'] . '</textarea>';
		}
		die();
	} catch (PDOException $e) {
		die_error($_POST['xml'], 'dbError: ' . $e->getMessage());
	}
}

// Toggle state of feed
if (isset($_POST['state'])) {
	try {
		$query_update = $DB->prepare("UPDATE feeds SET enabled = :state WHERE id = :id");
		$query_update->bindParam(':state', $_POST['state'], PDO::PARAM_INT);
		$query_update->bindParam(':id', $_POST['id'], PDO::PARAM_INT);
		$query_update->execute();
		if ($query_update->rowCount() > 0) {
			echo($_POST['state']);
		} else {
			echo(1 - intval($_POST['state']));
		}
	} catch (PDOException $e) {
		die_error($_POST['state'], 'dbError: ' . $e->getMessage());
	}
	die();
}

// Add/edit feed
if (isset($_POST['id']) && !empty($_POST['name'])) {
	$charset = strtoupper($_POST['charset']);
	$name = strtolower($_POST['name']);
	try {
		if (empty($_POST['id'])) {
			// Add feed
			$query = $DB->query("SELECT count(*) FROM feeds WHERE name=" . $DB->quote($_POST['name']));
			if ($query->fetchColumn())
				die_error('', '[ERROR] Feed with name <strong>' . $_POST['name'] . '</strong> already present!');
			$query_update = $DB->prepare("INSERT INTO feeds (name, description, charset, url, method, method_detail, filter) VALUES (:name, :description, :charset, :url, :method, :method_detail, :filter)");
			$mode = 'added';
		} else {
			// Edit feed
			$query_update = $DB->prepare("UPDATE feeds SET name = :name, description = :description, charset = :charset, url = :url, method = :method, method_detail = :method_detail, filter = :filter WHERE id = :id");
			$query_update->bindParam(':id', $_POST['id'], PDO::PARAM_INT);
			$mode = 'edited';
		}
		$query_update->bindParam(':name', $name, PDO::PARAM_STR);
		$query_update->bindParam(':description', $_POST['description'], PDO::PARAM_STR);
		$query_update->bindParam(':charset', $charset, PDO::PARAM_STR);
		$query_update->bindParam(':url', $_POST['url'], PDO::PARAM_STR);
		$query_update->bindParam(':method', $_POST['method'], PDO::PARAM_STR);
		$query_update->bindParam(':method_detail', $_POST['method_detail'], PDO::PARAM_STR);
		$query_update->bindParam(':filter', $_POST['filter'], PDO::PARAM_STR);
		$query_update->execute();
		$query_log->execute(array($_POST['id'], time(), "Successfully " . $mode . " feed <strong>" . $_POST['name'] . "</strong>"));
	} catch (PDOException $e) {
		die_error($_POST['id'], 'dbError: ' . $e->getMessage());
	}
}

// Delete feed
if (!empty($_POST['delete'])) {
	if ($DB->exec("DELETE FROM feeds WHERE id=" . $DB->quote($_POST['delete']))) {
		$query_log->execute(array($_POST['delete'], time(), "Successfully delete feed with id=" . $_POST['delete']));
		die('OK');
	} else {
		die_error($_POST['delete'], 'ERROR: Deleted record with id=' . $_POST['delete'] . ' not found!');
	}
}

// Save settings in DB
if (isset($_POST['locale'])) {
	try {
		if ($_POST['password'] !== '**********') {
			$query_update = $DB->prepare("UPDATE settings SET admin_pass = :password");
			$query_update->bindParam(':password', sha1($_POST['password']), PDO::PARAM_STR);
			$query_update->execute();
			$query_log->execute(array('password', time(), "Successfully changed admin password"));
		}
		$query_update = $DB->prepare("UPDATE settings SET locale = :locale");
		$query_update->bindParam(':locale', $_POST['locale'], PDO::PARAM_STR);
		$query_update->execute();
		$query_log->execute(array('settings', time(), "Successfully updated settings"));
		header("Location: " . $_SERVER['SCRIPT_NAME'] . '?admin');
		die();
	} catch (PDOException $e) {
		die_error('settings', 'dbError: ' . $e->getMessage());
	}
}

?>
<!DOCTYPE html>
<html>
    <head>
        <title>Full text RSS feeds proxy</title>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
        <link href="favicon.ico" rel="icon" type="image/x-icon" />
        <link rel="stylesheet" href="//maxcdn.bootstrapcdn.com/bootstrap/3.2.0/css/bootstrap.min.css">
        <link rel="stylesheet" href="//maxcdn.bootstrapcdn.com/bootstrap/3.2.0/css/bootstrap-theme.min.css">
        <script src="//ajax.googleapis.com/ajax/libs/jquery/1.9.1/jquery.min.js"></script>
        <script src="//maxcdn.bootstrapcdn.com/bootstrap/3.2.0/js/bootstrap.min.js"></script>
        <link rel="stylesheet" href="//www.fuelcdn.com/fuelux/3.0.2/css/fuelux.min.css">
        <script src="//www.fuelcdn.com/fuelux/3.0.2/js/fuelux.min.js"></script>
    </head>
    <body>
        <div class="container">
	    <?php
		$query = '';
	    if (isset($_REQUEST['admin'])) {
		// Show admin panel
		if (!isset($_SESSION['u_login'])) {
		    ?>
		    <div class="row text-center">
			<label for="InputPassword1">Please enter password for access:</label>
			<form class="form-inline" role="form" method="post">
			    <div class="form-group">
				<label class="sr-only" for="InputPassword2">Password</label>
				<input type="password" name="passwd" class="form-control" id="InputPassword2" placeholder="Password">
			    </div>
			    <button type="submit" class="btn btn-large btn-primary">Sign in</button>
			</form>
		    </div>
	<?
	echo '</div></body></html>';
	exit;
    }
    ?>
    <script>
	function Add() {
		$('.modal').on('hidden.bs.modal', function(){
			$(this).find('form')[0].reset();
		});
		$('#EditModalLabel').text('Add RSS feed');
		$('#SubmitText').text('Add');
		$('#SubmitIcon').attr('class', 'glyphicon glyphicon-plus');
		$('#EditModal').modal();
	}
	function Edit(id) {
		$('#id').val(id);
		var tds = Array.prototype.slice.call(document.getElementById("row_"+id).getElementsByTagName("td"));
		$('#name').val(tds[0].innerHTML.match(/>(\w+)</)[1]);
		$('#description').val(tds[1].innerHTML);
		$('#charset').val(tds[2].innerHTML);
		$('#url').val(tds[3].innerHTML.match(/>(.+)</)[1]);
		$('#method').selectlist('selectByValue', tds[4].innerHTML);
		$('#method_detail').val(tds[4].getAttribute('title'));
		$('#filter').val(tds[5].getAttribute('title'));
		$('#EditModalLabel').text('Edit RSS feed');
		$('#SubmitText').text('Save');
		$('#SubmitIcon').attr('class', 'glyphicon glyphicon-floppy-save');
		$('#EditModal').modal();
	}
	function Delete(id) {
		if (confirm('Are you sure you want to delete feed?')) {
			$.ajax({
					url: '<?echo $_SERVER['SCRIPT_NAME'];?>',
					type: 'POST',
					data: {'delete':id},
					success: function (result) {
						if (result == 'OK') {
							$("#row_"+id).remove();
						} else {
							alert(result);
						}
					}
				});
			}
	}
	function Toggle(id, state) {
		$.ajax({
				url: '<?echo $_SERVER['SCRIPT_NAME'];?>',
				type: 'POST',
				data: {'state':state, 'id':id},
				success: function (result) {
					if (result == '0') {
						$('#toggle'+id).button('reset');
						$('#toggle'+id).attr('class', 'btn btn-default btn-xs');
						$('#toggle'+id).attr('title', 'Disabled');
						$('#toggle'+id).attr('onClick', 'Toggle('+id+',1)');
						$('#toggle'+id+' span').attr('class','glyphicon glyphicon-ban-circle');
					} else if (result == '1') {
						console.log($('#toggle'+id));
						$('#toggle'+id).button('reset');
						$('#toggle'+id).attr('class', 'btn btn-success btn-xs');
						$('#toggle'+id).attr('title', 'Enabled');
						$('#toggle'+id).attr('onClick', 'Toggle('+id+',0)');
						$('#toggle'+id+' span').attr('class','glyphicon glyphicon-ok-circle');
					}
				}
			});
	}
	function Preview(id) {
		$.ajax({
			url: '<?echo $_SERVER['SCRIPT_NAME'];?>',
			type: 'POST',
			data: {'feed':id, 'force':'', 'preview':''},
			success: function (result) {
				if (result !== '') {
					$('#LogModalLabel').text('Preview feed (one first item)');
					$('#LogContent').html(result);
					$('#LogModal').modal();
				} else {
					alert('ERROR fetch feed!');
				}
			}
		});
	}
	function Log(id) {
		$.ajax({
			url: '<?echo $_SERVER['SCRIPT_NAME'];?>',
			type: 'POST',
			data: {'log':id},
			success: function (result) {
				if (result !== '') {
					$('#LogModalLabel').text('Feed log');
					$('#LogContent').html(result);
					$('#LogModal').modal();
				} else {
					alert('ERROR fetch log!');
				}
			}
		});
	}
	function ShowXML(id) {
		$.ajax({
				url: '<?echo $_SERVER['SCRIPT_NAME'];?>',
				type: 'POST',
				data: {'xml':id},
				success: function (result) {
					if (result !== '') {
						$(this).button('reset');
						$('#LogModalLabel').text('Feed XML');
						$('#LogContent').html(result);
						$('#LogModal').modal();
					} else {
						alert('ERROR fetch xml!');
					}
				}
			});
	}
    </script>
    <nav class="navbar navbar-default" role="navigation">
        <div class="container-fluid">
    	<!-- Brand and toggle get grouped for better mobile display -->
    	<div class="navbar-header">
    	    <button type="button" class="navbar-toggle collapsed" data-toggle="collapse" data-target="#bs-navbar-collapse-1">
    		<span class="sr-only">Toggle navigation</span>
    		<span class="icon-bar"></span>
    		<span class="icon-bar"></span>
    		<span class="icon-bar"></span>
    	    </button>
    	    <a class="navbar-brand" href="<? echo $_SERVER['REQUEST_URI']; ?>">Full text RSS proxy admin panel</a>
    	</div>

    	<!-- Collect the nav links, forms, and other content for toggling -->
    	<div class="collapse navbar-collapse" id="bs-navbar-collapse-1">
    	    <div class="nav navbar-nav navbar-right">
    		<button class="btn btn-default navbar-btn" data-toggle="modal" data-target="#SettingsModal"><span class="glyphicon glyphicon-wrench"></span> Settings</button>
    		<button class="btn btn-primary navbar-btn" onClick="Add();"><span class="glyphicon glyphicon-plus"></span> Add RSS feed</button>
    		<button class="btn btn-info navbar-btn" onClick="Log(0);"><span class="glyphicon glyphicon-list"></span> View log</button>
    		<button class="btn btn-warning navbar-btn" onClick="window.location.href = '?logout'"><span class="glyphicon glyphicon-log-out"></span> Logout</button>
    	    </div>
    	</div><!-- /.navbar-collapse -->
        </div><!-- /.container-fluid -->
    </nav>

    <!-- Add user modal -->
    <div class="modal fade" id="EditModal" tabindex="-1" role="dialog" aria-labelledby="EditLabel" aria-hidden="true">
        <div class="modal-dialog">
    	<div class="modal-content">
    	    <form class="form-horizontal" role="form" action="?admin" method="POST">
    		<div class="modal-header">
    		    <button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">&times;</span><span class="sr-only">Close</span></button>
    		    <h4 class="modal-title" id="EditModalLabel">Add RSS feed</h4>
    		</div>
    		<div class="modal-body">
    		    <div class="form-group">
    			<label for="name" class="col-sm-4 control-label">Name</label>
    			<div class="col-sm-6">
    			    <input name="name" id="name" class="form-control" required>
    			    <input type="hidden" id="id" name="id" value="">
    			</div>
    		    </div>
    		    <div class="form-group">
    			<label for="description" class="col-sm-4 control-label">Description</label>
    			<div class="col-sm-6">
    			    <input name="description" id="description" class="form-control" required>
    			</div>
    		    </div>
    		    <div class="form-group">
    			<label for="charset" class="col-sm-4 control-label">Charset</label>
    			<div class="col-sm-6">
    			    <input name="charset" id="charset" class="form-control" value="UTF-8" required>
    			</div>
    		    </div>
    		    <div class="form-group">
    			<label for="url" class="col-sm-4 control-label">URL</label>
    			<div class="col-sm-6">
    			    <input name="url" id="url" type="url" class="form-control" required>
    			</div>
    		    </div>
    		    <div class="form-group">
    			<label for="method" class="col-sm-4 control-label">Full article method</label>
    			<div class="col-sm-6">
    			    <div class="btn-group selectlist" data-resize="auto" data-initialize="selectlist" id="method">
    				<button class="btn btn-default dropdown-toggle" data-toggle="dropdown" type="button">
    				    <span class="selected-label"></span>
    				    <span class="caret"></span>
    				    <span class="sr-only">Toggle Dropdown</span>
    				</button>
    				<ul class="dropdown-menu" role="menu">
    				    <li data-value="Readability"><a href="#">Readability</a></li>
    				    <li data-value="Simple HTML DOM"><a href="#">Simple HTML DOM</a></li>
    				</ul>
    				<input class="hidden hidden-field" name="method" readonly="readonly" aria-hidden="true" type="text"/>
    			    </div>
    			</div>
    		    </div>
    		    <div class="form-group">
    			<label for="method_detail" class="col-sm-4 control-label">Detail for method</label>
    			<div class="col-sm-6">
    			    <input name="method_detail" id="method_detail" class="form-control">
    			</div>
    		    </div>
    		    <div class="form-group">
    			<label for="filter" class="col-sm-4 control-label">Filters (replace string or regex) for remove unnecessary strings <br /> (one per lines)</label>
    			<div class="col-sm-6">
    			    <textarea name="filter" id="filter" class="form-control" wrap="off" rows="4"></textarea>
    			</div>
    		    </div>
    		</div>
    		<div class="modal-footer">
    		    <button type="button" class="btn btn-default" data-dismiss="modal"><span class="glyphicon glyphicon-remove"></span> Close</button>
    		    <button id="SubmitButton" type="submit" class="btn btn-primary btn-confirm"><span id="SubmitIcon" class="glyphicon glyphicon-plus"></span> <span id="SubmitText">Add</span></button>
    		</div>
    	    </form>
    	</div>
        </div>
    </div>

    <!-- Log modal -->
    <div class="modal" id="LogModal" tabindex="-1" role="dialog" aria-labelledby="LogModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
    	<div class="modal-content">
    	    <div class="modal-header">
    		<button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">&times;</span><span class="sr-only">Close</span></button>
    		<h4 class="modal-title" id="LogModalLabel">Feed log</h4>
    	    </div>
    	    <div class="modal-body">
    		<div id="LogContent">Empty</div>
    	    </div>
    	    <div class="modal-footer">
    		<button type="button" class="btn btn-default" data-dismiss="modal"><span class="glyphicon glyphicon-remove"></span> Close</button>
    	    </div>
    	</div>
        </div>
    </div>

    <!-- Settings modal -->
    <div class="modal fade" id="SettingsModal" tabindex="-1" role="dialog" aria-labelledby="SettingsModalLabel" aria-hidden="true">
        <div class="modal-dialog">
    	<div class="modal-content">
    	    <form class="form-horizontal" role="form" action="?config" method="POST">
    		<div class="modal-header">
    		    <button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">&times;</span><span class="sr-only">Close</span></button>
    		    <h4 class="modal-title" id="SettingsModalLabel">Settings</h4>
    		</div>
    		<div class="modal-body">
    		    <div class="form-group">
    			<label for="password" class="col-sm-4 control-label">Admin password</label>
    			<div class="col-sm-6">
    			    <input name="password" id="name" type="password" class="form-control" value="**********" required>
    			</div>
    		    </div>
		    <div class="form-group">
			<label for="locale" class="col-sm-4 control-label">Locale (LC_ALL)</label>
			<div class="col-sm-6">
			    <input name="locale" id="locale" class="form-control" value="<?php echo $settings['locale'];?>" required>
			</div>
		    </div>
    		</div>
    		<div class="modal-footer">
    		    <button type="button" class="btn btn-default" data-dismiss="modal"><span class="glyphicon glyphicon-remove"></span> Close</button>
    		    <button type="submit" class="btn btn-primary btn-confirm"><span class="glyphicon glyphicon-floppy-save"></span> Save changes</button>
    		</div>
    	    </form>
    	</div>
        </div>
    </div>

    <?php
    // Check required extension
    $required_extension = array('SimpleXML', 'iconv', 'curl', 'intl');
	foreach ($required_extension as $ext) {
		if (!extension_loaded($ext)) echo('<div class="alert alert-danger" role="alert">PHP extension <strong>' . $ext . '</strong> not installed!</div>');
	}
    ?>
    <table id="list" class="table table-striped table-hover">
        <thead><tr><th>Name</th><th>Description</th><th>Charset</th><th>URL</th><th width="140">Method</th><th>Filter</th><th>RSS&nbsp;XML</th><th width="180">Action</th></tr></thead>
        <tbody>
		<?php
		try {
			$query = $DB->query("SELECT * FROM feeds", PDO::FETCH_ASSOC);
		} catch (PDOException $e) {
			die_error('list', 'dbError: ' . $e->getMessage());
		}
		foreach ($query as $row) {
			echo '<tr id="row_' . $row['id'] . '"><td><a href="?feed=' . $row['name'] . '" target="_blank">' . $row['name'] . '</a></td><td>' . $row['description'] . '</td>';
			echo '<td>' . $row['charset'] . '</td><td><a href="' . $row['url'] . '" target="_blank">' . $row['url'] . '</a></td>';
			echo '<td data-toggle="tooltip" title="' . htmlspecialchars($row['method_detail']) . '">' . $row['method'] . '</td>';
			echo '<td align="center" data-toggle="tooltip" title="' . htmlspecialchars($row['filter']) . '"><span class="label label-';
			echo empty($row['filter']) ? 'default">Empty' : 'info">Available';
			echo '</span></td>';
			if (empty($row['xml'])) {
				echo '<td><span class="label label-default">Empty</span></td>';
			} else {
				echo '<td><button type="button" onClick="ShowXML(' . $row['id'] . ')" class="btn btn-info btn-xs" data-toggle="tooltip" title="Last update: ' . date('d.m.Y H:i:s', $row['lastupdate']) . '">Available';
				echo '</button></td>';
			}
			echo '<td>';
			if ($row['enabled'] == '1') {
				echo '<button id="toggle' . $row['id'] . '" type="button" class="btn btn-success btn-xs" data-toggle="tooltip" title="Enabled" onClick="Toggle(' . $row['id'] . ',0)"><span class="glyphicon glyphicon-ok-circle"></span></button> ';
			} else {
				echo '<button id="toggle' . $row['id'] . '" type="button" class="btn btn-default btn-xs" data-toggle="tooltip" title="Disabled" onClick="Toggle(' . $row['id'] . ',1)"><span class="glyphicon glyphicon-ban-circle"></span></button> ';
			}
			echo '<a data-toggle="tooltip" title="Force refresh feed" class="btn btn-default btn-xs" href="?feed=' . $row['name'] . '&force" target="_blank"><span class="glyphicon glyphicon-refresh"></span></a> ';
			echo '<button type="button" data-toggle="tooltip" title="Preview" class="btn btn-default btn-xs" onClick="Preview(' . $row['id'] . ')"><span class="glyphicon glyphicon-eye-open"></span></button> ';
			echo '<button type="button" data-toggle="tooltip" title="Edit" class="btn btn-primary btn-xs" onClick="Edit(' . $row['id'] . ')"><span class="glyphicon glyphicon-edit"></span></button> ';
			echo '<button type="button" data-toggle="tooltip" title="View log" class="btn btn-info btn-xs" onClick="Log(' . $row['id'] . ')"><span class="glyphicon glyphicon-list"></span></button> ';
			echo '<button type="button" data-toggle="tooltip" title="Delete" class="btn btn-danger btn-xs" onClick="Delete(' . $row['id'] . ')"><span class="glyphicon glyphicon-remove"></span></button>';
			echo '</td>';
			echo '</tr>';
		}
		?>
        </tbody></table>
    <?php
} else {
    // Show list of feeds
    ?>
    <div class="page-header">
        <h1>Full text RSS feeds proxy <small>by Nomadic</small></h1>
    </div>
    <div class="panel panel-default">
        <div class="panel-heading">
    	<h3 class="panel-title"> Available feeds
    	    <img src="favicon.ico">
    	</h3>
        </div>
        <div class="panel-body">
    	<ol>
		<?php
		try {
			$query = $DB->query("SELECT name,description FROM feeds WHERE enabled > 0", PDO::FETCH_ASSOC);
		} catch (PDOException $e) {
			die_error('select', 'dbError: ' . $e->getMessage());
		}
		foreach ($query as $row) {
			echo('<li><a href="?feed=' . $row['name'] . '" target="_blank">' . $row['description'] . '</a></li>');
		}
		?>
    	</ol>
        </div> <!-- panel-body -->
    </div> <!-- panel -->
    <?php
}
// Close database
$DB = null;

function die_error($id, $return)
{
	header('Content-Type: text/html; charset=utf-8');
	global $query_log;
	try {
		$query_log->execute(array($id, time(), $return));
	} catch (PDOException $e) {
		die('dbError: ' . $e->getMessage().'<br />'.$return);
	}
	die($return);
}

		?>
</div> <!-- container -->
</body>
</html>