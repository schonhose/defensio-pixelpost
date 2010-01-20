<?php
/*
Requires Pixelpost version 1.7 or newer
Defensio Callback function

Written by: Schonhose
@:			schonhose@pixelpost.org
WWW:		http://foto.schonhose.nl/

Pixelpost www: http://www.pixelpost.org/

License: http://www.gnu.org/copyleft/gpl.html

This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
*/

require_once ('../../../includes/pixelpost.php');
require_once ('pixelpost/defensio_pixelpost.php');

/**
 * Make a database connection and get the defensio configuration
 */

$link = mysql_connect($pixelpost_db_host, $pixelpost_db_user, $pixelpost_db_pass);

$db_selected = mysql_select_db($pixelpost_db_pixelpost);

$cfgrow = sql_array("SELECT * FROM " . $pixelpost_db_prefix . "config");
$defensio_result = mysql_query("SELECT * FROM `{$pixelpost_db_prefix}defensio` LIMIT 1") or
    die(mysql_error());
$defensio_conf = mysql_fetch_array($defensio_result);

if (!isset($_GET['id']) || ($_GET['id'] != md5($defensio_conf['key']))) {
    die('Could not authenticate. Bye bye!');
}

/**
 * We need the raw POST data. There is a function in the Defensio Library
 * (Defensio::handlePostDocumentAsyncCallback()) but somehow this throws an
 * error. So we do it manually using PHP code.
 */
$response = simplexml_load_string(file_get_contents('php://input'));
if (is_object($response)) {
    $callback_result[0] = 200;
    $callback_result[1] = $response;
    $result = defensio_process_comment_pixelpost($callback_result, false);
}
?>