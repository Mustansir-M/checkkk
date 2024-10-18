<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * TODO describe file report3
 *
 * @package    block_docmanager
 * @copyright  2024 YOUR NAME <your@email.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


 require_once('../../../config.php');
 require_once($CFG->libdir . '/tablelib.php');
 require_once('docmanager_filter_form.php');
 require_login();
 require_capability('block/docmanager:viewreports', context_system::instance());
 
 $PAGE->set_url(new moodle_url('/blocks/docmanager/reporting/report3.php'));
 $PAGE->set_context(context_system::instance());
 $PAGE->set_title(get_string('report', 'block_docmanager'));
 $PAGE->set_heading(get_string('report', 'block_docmanager'));
 $PAGE->set_pagelayout('report');
 
 echo $OUTPUT->header();

$currenttime = time();
$nextmonth = strtotime('+1 month');

$expired_docs = $DB->get_records_sql("
    SELECT f.id, f.userid, f.filename, f.expiry_date, u.firstname, u.lastname 
    FROM {block_docmanager_files} f
    JOIN {user} u ON f.userid = u.id
    WHERE f.expiry_date IS NOT NULL AND f.expiry_date < ?
", array($currenttime));

$soon_docs = $DB->get_records_sql("
    SELECT f.id, f.userid, f.filename, f.expiry_date, u.firstname, u.lastname 
    FROM {block_docmanager_files} f
    JOIN {user} u ON f.userid = u.id
    WHERE f.expiry_date IS NOT NULL AND f.expiry_date >= ? AND f.expiry_date < ?
", array($currenttime, $nextmonth));

$future_docs = $DB->get_records_sql("
    SELECT f.id, f.userid, f.filename, f.expiry_date, u.firstname, u.lastname 
    FROM {block_docmanager_files} f
    JOIN {user} u ON f.userid = u.id
    WHERE f.expiry_date IS NOT NULL AND f.expiry_date >= ?
", array($nextmonth));

$no_expiry_docs = $DB->get_records_sql("
    SELECT f.id, f.userid, f.filename, u.firstname, u.lastname 
    FROM {block_docmanager_files} f
    JOIN {user} u ON f.userid = u.id
    WHERE f.expiry_date IS NULL
");

$chart = new core\chart_bar();
$series = new core\chart_series(get_string('document_status', 'block_docmanager'), [count($expired_docs), count($soon_docs), count($future_docs), count($no_expiry_docs)]);
$chart->add_series($series);
$chart->set_labels([get_string('expired', 'block_docmanager'), get_string('soon_expiring', 'block_docmanager'), get_string('future_expiring', 'block_docmanager'), get_string('no_expiry_docs', 'block_docmanager')]);
 
$filterform = new docmanager_filter_form();
$filters = [];
$records_per_page = 10; // Number of records per page
$page = optional_param('page', 0, PARAM_INT);

if ($data = $filterform->get_data()) {
    $filters['username'] = !empty($data->username) ? implode(',', $data->username) : '';
    $filters['categories'] = !empty($data->categories) ? implode(',', $data->categories) : '';
}


echo '<div class="top-section">';
echo '<div class="left-summary">';
$filterform->display();
echo '</div>';
echo '<div class="right-chart small-chart">';
echo $OUTPUT->render_chart($chart, false);
echo '</div>';
echo '</div>';

 
 global $DB;
 $sql = "SELECT * FROM {block_docmanager_files} WHERE 1=1";
 $params = [];

if (!empty($filters['username'])) {
    $usernames = explode(',', $filters['username']);
    list($usernamesql, $usernameparams) = $DB->get_in_or_equal($usernames, SQL_PARAMS_NAMED);
    $sql .= " AND userid $usernamesql";
    $params = array_merge($params, $usernameparams);
}

// Filter by categories.
if (!empty($filters['categories'])) {
    $categories = explode(',', $filters['categories']);
    $conditions = [];
    $currentTimestamp = time();
    $futureTimestamp = strtotime("+1 month");

    foreach ($categories as $index => $category) {
        switch ($category) {
            case 'expired':
                $conditions[] = "(expiry_date IS NOT NULL AND expiry_date < :currenttime{$index})";
                $params["currenttime{$index}"] = $currentTimestamp;
                break;
            case 'expiring_soon':
                $conditions[] = "(expiry_date BETWEEN :currenttime{$index} AND :futuretime{$index})";
                $params["currenttime{$index}"] = $currentTimestamp;
                $params["futuretime{$index}"] = $futureTimestamp;
                break;
            case 'future':
                $conditions[] = "(expiry_date > :futuretime{$index})";
                $params["futuretime{$index}"] = $futureTimestamp;
                break;
            case 'no_expiry':
                $conditions[] = "expiry_date IS NULL";
                break;
        }
    }

    // Only add conditions if there are any.
    if (!empty($conditions)) {
        $sql .= " AND (" . implode(' OR ', $conditions) . ")";
    }
}

// Get the total number of records
$total_records = $DB->count_records_sql("SELECT COUNT(*) FROM ($sql) subquery", $params);

// Calculate offset and limit for pagination
$offset = $page * $records_per_page;
$sql .= " LIMIT $records_per_page OFFSET $offset";


$documents = $DB->get_records_sql($sql, $params);


// Download section:
echo html_writer::start_tag('form', [
    'method' => 'get', 
    'action' => new moodle_url('/blocks/docmanager/reporting/download.php'),
    'class'=> 'mb-3'
]);

echo html_writer::empty_tag('input', [
    'type' => 'hidden', 
    'name' => 'usernames', 
    'value' => $filters['username']
]);
echo html_writer::empty_tag('input', [
    'type' => 'hidden', 
    'name' => 'categories', 
    'value' => $filters['categories']
]);

echo html_writer::start_tag('select', [
    'name' => 'format', 
    'class' => 'custom-select mr-2'
]);
echo html_writer::tag('option', get_string('select_format', 'block_docmanager'), ['value' => '']);
echo html_writer::tag('option', 'CSV', ['value' => 'csv']);
echo html_writer::tag('option', 'PDF', ['value' => 'pdf']);
echo html_writer::end_tag('select');

echo html_writer::empty_tag('input', [
    'type' => 'submit', 
    'value' => get_string('download', 'block_docmanager'), 
    'class' => 'btn btn-primary'
]);

echo html_writer::end_tag('form');


// Displaying the Table
 $table = new html_table();

     $table->head = array(
         get_string('username', 'block_docmanager'), 
         get_string('filename', 'block_docmanager'),
         get_string('expiry_date', 'block_docmanager'),
     );
 foreach ($documents as $doc) {
    $row = new html_table_row();
    $row->cells[] = $doc->username;
    $row->cells[] = $doc->filename;
    $row->cells[] = $doc->expiry_date ? userdate($doc->expiry_date) : 'N/A';
    $table->data[] = $row;
 }
 $table->attributes['class'] = 'generaltable table-doc';

echo html_writer::table($table);

 // Pagination links
$baseurl = new moodle_url('/blocks/docmanager/reporting/report3.php', [
    'username' => $filters['username'],
    'categories' => $filters['categories']
]);

echo $OUTPUT->paging_bar($total_records, $page, $records_per_page, $baseurl);

//  Back to Dashboard button
 $back_url = new moodle_url('/my');
    echo html_writer::empty_tag('br');
    echo html_writer::tag('div',
        $OUTPUT->single_button($back_url, get_string('back_to_dash', 'block_docmanager'), 'get'),
        array('class' => 'back-to-dash-button')
    );
 
 echo $OUTPUT->footer();

 echo '<style>
    .top-section {
        display: flex;
        justify-content: space-between;
        margin-bottom: 30px;
    }
    .left-summary{
        width: 100%;
    }
   

    .right-chart {
        width: 100%;
    }
</style>';
 


// depreciated:

// // Create a download URL with the serialized parameters.
// $downloadurl = new moodle_url('/blocks/docmanager/reporting/download.php', [
//     'usernames' => $filters['username'],
//     'categories' => $filters['categories'],
//     'format' => 'csv'
// ]);

// $downloadpdfurl = new moodle_url('/blocks/docmanager/reporting/download.php', [
//     'usernames' => $filters['username'],
//     'categories' => $filters['categories'],
//     'format' => 'pdf'
// ]);
//  echo html_writer::tag('a', get_string('download', 'block_docmanager'), ['href' => $downloadurl, 'class' => 'btn btn-primary']);
//  echo html_writer::tag('a', get_string('download', 'block_docmanager'), ['href' => $downloadpdfurl, 'class' => 'btn btn-primary']);
