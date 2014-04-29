<?php
// This file is part of SCORM trends report for Moodle
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
 * Core Report class of graphs reporting plugin
 *
 * @package    scormreport_trends
 * @copyright  2013 Ankit Kumar Agarwal
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
require_once('reportlib.php');

require_once($CFG->dirroot.'/lib/graphlib.php');

/**
 * Main class for the trends report
 *
 * @package    scormreport_trends
 * @copyright  2013 Ankit Agarwal
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

function bar_chart($x_data, $y_data, $settings) {
	$barchart = new graph(count(x_data)*30+60,100);
	$barchart->parameter = array_merge($barchart->parameter,array('shadow'=>'none','x_label_angle'=>0,'x_grid'=>'none'),$settings);

	$barchart->x_data = $x_data;
	$barchart->y_data['bars'] = $y_data;
	$barchart->y_format['bars'] = array('bar' => 'fill', 'colour' => 'blue', 'shadow_offset' => 0);
	$barchart->y_order = array('bars');
	$y_min = min($y_data);
	$y_max = max($y_data);
	$barchart->parameter['y_axis_gridlines'] = max(2,min($y_max-$y_min,6));
	$barchart->parameter['y_min_left'] = $y_min;
	$barchart->parameter['y_max_left'] = $y_max;

	$barchart->init();
	$barchart->draw_text();
	$barchart->draw_data();
	ob_start();
	ImagePNG($barchart->image);
	$imageData = ob_get_contents();
	ob_end_clean();
	$data = base64_encode($imageData);
	return "<img src=\"data:image/png;base64,".$data."\">";
}

class scorm_trends_report extends scorm_default_report {
	public function get_sco_summary($sco) {
		global $DB, $OUTPUT, $PAGE;

		// Construct the SQL.
		$select = 'SELECT DISTINCT '.$DB->sql_concat('st.userid', '\'#\'', 'COALESCE(st.attempt, 0)').' AS uniqueid, ';
		$select .= 'st.userid AS userid, st.scormid AS scormid, st.attempt AS attempt, st.scoid AS scoid ';
		$from = 'FROM {scorm_scoes_track} st ';
		$where = ' WHERE st.userid ' .$this->usql. ' and st.scoid = ?';

		$sqlargs = array_merge($this->params, array($sco->id));
		$attempts = $DB->get_records_sql($select.$from.$where, $sqlargs);
		// Determine maximum number to loop through.
		$loop = get_sco_question_count($sco->id, $attempts);

		for ($i = 0; $i < $loop; $i++) {
			$tabledata[] = array(
				'type' => '',
				'id' => '',
				'result' => array());
		}
		foreach ($attempts as $attempt) {
			if ($trackdata = scorm_get_tracks($sco->id, $attempt->userid, $attempt->attempt)) {
				foreach ($trackdata as $element => $value) {
					if (preg_match('/^cmi.interactions.(\d+)/',$element,$matches)) {
						$i = $matches[1];
						if(preg_match('/.type$/',$element)) {
							$tabledata[$i]['type'] = $value;
						} else if (preg_match('/^cmi.interactions.\d+.id$/',$element)) {
							$tabledata[$i]['id'] = $value;
						} else if (preg_match('/.result$/',$element)) {
							if (isset($tabledata[$i]['result'][$value])) {
								$tabledata[$i]['result'][$value]++;
							} else {
								$tabledata[$i]['result'][$value] = 1;
							}
						}
					}
				}
			}
		} // End of foreach loop of attempts.

		return $tabledata;
	}

    /**
     * Displays the trends report
     *
     * @param stdClass $scorm full SCORM object
     * @param stdClass $cm - full course_module object
     * @param stdClass $course - full course object
     * @param string $download - type of download being requested
     * @return bool true on success
	 */

    public function display($scorm, $cm, $course, $download) {
        global $DB, $OUTPUT, $PAGE;

        $contextmodule = context_module::instance($cm->id);
        $scoes = $DB->get_records('scorm_scoes', array("scorm" => $scorm->id), 'id');

        // Groups are being used, Display a form to select current group.
        if ($groupmode = groups_get_activity_groupmode($cm)) {
                groups_print_activity_menu($cm, new moodle_url($PAGE->url));
        }

        // Find out current group.
        $currentgroup = groups_get_activity_group($cm, true);

        // Group Check.
        if (empty($currentgroup)) {
            // All users who can attempt scoes.
            $students = get_users_by_capability($contextmodule, 'mod/scorm:savetrack', 'u.id' , '', '', '', '', '', false);
            $allowedlist = empty($students) ? array() : array_keys($students);
        } else {
            // All users who can attempt scoes and who are in the currently selected group.
            $groupstudents = get_users_by_capability($contextmodule, 'mod/scorm:savetrack', 'u.id', '', '', '', $currentgroup, '', false);
            $allowedlist = empty($groupstudents) ? array() : array_keys($groupstudents);
        }

        // Do this only if we have students to report.
        if (!empty($allowedlist)) {

			list($this->usql, $this->params) = $DB->get_in_or_equal($allowedlist);

            foreach ($scoes as $sco) {
                if ($sco->launch != '') {
					echo $OUTPUT->heading($sco->title);
					$tabledata = $this->get_sco_summary($sco);
                    $columns = array('question', 'type', 'results');
                    $headers = array(
                        get_string('interactionheader', 'scormreport_trends'),
                        get_string('type', 'scormreport_trends'),
						get_string('results', 'scormreport_trends')
					);

                    // Format data for tables and generate output.
                    $formatted_data = array();
                    if (!empty($tabledata)) {
						$table = new flexible_table('mod-scorm-trends-report-'.$sco->id);

						$table->define_columns($columns);
						$table->define_headers($headers);
						$table->define_baseurl($PAGE->url);

						// Don't show repeated data.
						$table->column_suppress('question');
						$table->column_suppress('element');

						$table->setup();

						foreach ($tabledata as $interaction => $rowinst) {
							$sum = $rowinst['result'];
							$topscore = max(array_keys($sum));
							for($j = 0; $j < $topscore; $j++) {
								if(!isset($sum[$j])) {
									$sum[$j] = 0;
								}
							}
							ksort($sum);
							$barchart = bar_chart(array_keys($sum), array_values($sum),array('x_label'=>'Result','y_label_left'=>'Frequency', 'title' => ""));

							$table->add_data(array(
								$rowinst['id'],
								$rowinst['type'],
								$barchart
							));
                        }
                        $table->finish_output();
					for ($i = 0; $i < count($tabledata); $i++) {
					}
                    } // End of generating output.

                }
            }
        } else {
            echo $OUTPUT->notification(get_string('noactivity', 'scorm'));
		}

        return true;
    }
}
