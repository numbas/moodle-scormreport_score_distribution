<?php
// This file is part of the SCORM score distributions report for Moodle
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
 *
 * @package    scormreport_scoredistribution
 * @copyright  2014 Newcastle University, based on work by Ankit Kumar Agarwal
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/lib/graphlib.php');

function fill_axis_increments(&$data) {
	$increments = array(1,0.5,0.25,0.05,0.01);
	$g = 1;
	foreach(array_keys($data) as $x) {
		foreach($increments as $inc) {
			if((int)($x*100) % ($inc*100) == 0) {
				break;
			}
		}
		$g = min($g,$inc);
	}
	for($x=0; $x<max(array_keys($data)); $x += $g) {
		$x = (string)$x;
		if(!array_key_exists($x,$data)) {
			$data[$x] = 0;
		}
	}
}

function gcd($a,$b) {
    return ($a % $b) ? gcd($b,$a % $b) : $b;
}

function bar_chart($x_data, $y_data, $settings) {
	$barchart = new graph(min(400,count($x_data)*60+60),150);
	$barchart->parameter = array_merge($barchart->parameter,array('shadow'=>'none','x_label_angle'=>0,'x_grid'=>'none'),$settings);

	foreach($x_data as $i => $val) {
		$x_data[$i] = number_format($val,2,'.','');
	}
	$num = array_sum($y_data);
	foreach($y_data as $i => $val) {
		$y_data[$i] = 100*$val/$num;
	}
	$barchart->x_data = $x_data;
	$barchart->y_data['bars'] = $y_data;
	$barchart->y_format['bars'] = array('bar' => 'fill', 'colour' => 'blue', 'shadow_offset' => 0);
	$barchart->y_order = array('bars');
	$barchart->parameter['y_axis_gridlines'] = 5;
	$barchart->parameter['y_min_left'] = 0;
	$barchart->parameter['y_max_left'] = 100;
	return draw_graph($barchart);
}

function draw_graph($graph) {
	$graph->init();
	$graph->draw_text();
	$graph->draw_data();
	ob_start();
	ImagePNG($graph->image);
	$imageData = ob_get_contents();
	ob_end_clean();
	$data = base64_encode($imageData);
	return "<img src=\"data:image/png;base64,".$data."\">";
}

// mean of list of numbers
function array_mean($data) {
	if(empty($data)) {
		return 0;
	}
	return array_sum($data)/count($data);
}

function group($data) {
	$out = array();
	foreach ($data as $value) {
		if(isset($data[$value])) {
			$out[$value] += 1;
		} else {
			$out[$value] = 1;
		}
	}
	return $out;
}

// mean of value=>frequency array
function freq_mean($data) {
	$total = 0;
	$n = 0;
	foreach ($data as $result => $frequency) {
		$total += (float)$result*$frequency;
		$n += $frequency;
	}
	return $total/$n;
}

/**
 * Main class for the score distribution report
 *
 * @package    scormreport_scoredistribution
 * @copyright  2014 Newcastle University, based on 2013 Ankit Agarwal
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scorm_scoredistribution_report extends scorm_default_report {
	public function get_sco_summary($sco) {
		global $DB, $OUTPUT, $PAGE;

		// Construct the SQL.
		$select = 'SELECT DISTINCT '.$DB->sql_concat('st.userid', '\'#\'', 'COALESCE(st.attempt, 0)').' AS uniqueid, ';
		$select .= 'st.userid AS userid, st.scormid AS scormid, st.attempt AS attempt, st.scoid AS scoid ';
		$from = 'FROM {scorm_scoes_track} st ';
		$where = ' WHERE st.userid ' .$this->usql. ' and st.scoid = ?';

		$sqlargs = array_merge($this->params, array($sco->id));
		$attempts = $DB->get_records_sql($select.$from.$where, $sqlargs);

		$numcomplete = 0;
		$total_scores = array();

		foreach ($attempts as $attempt) {
			if ($trackdata = scorm_get_tracks($sco->id, $attempt->userid, $attempt->attempt)) {
				foreach ($trackdata as $element => $value) {
					if($element=='cmi.completion_status' && $value=='completed') {
						$numcomplete += 1;
					}
					else if ($element=='cmi.score.raw') {
						$total_scores[] = $value;
					}
					else if ($element=='cmi.score.max') {
						$max_score = $value;
					}
					else if (preg_match('/^cmi.interactions.(\d+)/',$element,$matches)) {
						$i = $matches[1];
						if(!isset($data[$i])) {
							$data[$i] = array(
								'type' => '',
								'id' => '',
								'result' => array());
						}
						if($element=="cmi.interactions.$i.type") {
							$data[$i]['type'] = $value;
						} else if($element=="cmi.interactions.$i.id") {
							$data[$i]['id'] = $value;
						} else if($element=="cmi.interactions.$i.result") {
							if (isset($data[$i]['result'][$value])) {
								$data[$i]['result'][$value]++;
							} else {
								$data[$i]['result'][$value] = 1;
							}
						}
					}
				}
			}
		}

		$newdata = array();
		foreach($data as $i => $arr) {
			$id = $arr['id'];
			if(isset($newdata[$id])) {
				foreach($arr['result'] as $value => $frequency) {
					if(isset($newdata[$id]['result'][$value])) {
						$newdata[$id]['result'][$value] += $frequency;
					} else {
						$newdata[$id]['result'][$value] = $frequency;
					}
				}
			} else {
				$newdata[$id] = $arr;
			}
		}
		$data = $newdata;

		function compare_interactions($a, $b)
		{
			return strcmp($a["id"], $b["id"]);
		}

		usort($data,'compare_interactions');
		$attemptdata = array(
			'numcomplete' => $numcomplete,
			'numattempts' => count($attempts),
			'total_scores' => $total_scores,
			'max_score' => $max_score
		);
		return array($attemptdata,$data);
	}

    /**
     * Displays the score distribution report
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
					list($attemptdata,$tabledata) = $this->get_sco_summary($sco);
					echo $OUTPUT->heading(get_string('scoheading','scormreport_scoredistribution',$sco->title));

					$score_frequencies = group($attemptdata['total_scores']);
					ksort($score_frequencies);
					$percent_complete = (int)(100*$attemptdata['numcomplete']/$attemptdata['numattempts']);
?>
	<p><?php echo get_string($attemptdata['numattempts']==1 ? 'oneattempt' : 'numattempts','scormreport_scoredistribution',$attemptdata['numattempts']); ?>, of which <?php echo get_string('numcomplete','scormreport_scoredistribution',$attemptdata['numcomplete']); ?> (<?php echo $percent_complete ?>%).</p>
	<p><?php echo get_string('meanscore','scormreport_scoredistribution',number_format(array_mean($attemptdata['total_scores']),2,'.','')); ?></p>
	<?php echo $OUTPUT->heading(get_string('results','scormreport_scoredistribution'),4); ?>
	<div><?php 
					fill_axis_increments($score_frequencies);
					ksort($score_frequencies);
					$x_data = array_keys($score_frequencies);
					$y_data = array_values($score_frequencies);
					$sum = $attemptdata['numattempts'];
					foreach($y_data as $i => $y) {
						$y_data[$i] = $sum;
						$sum -= $y;
					}
					foreach($y_data as $i => $y) {
						$y_data[$i] *= 100/$attemptdata['numattempts'];
					}
					$chart = new graph(800,300);
					$chart->x_data = $x_data;
					$chart->y_data['line'] = $y_data;
					$chart->y_format['line'] = array('line' => 'brush', 'brush_size' => 3, 'point' => 'none', 'colour' => 'red');
					$chart->y_order = array('line');
					$chart->parameter = array_merge($chart->parameter,array(
						'title' => '',
						'shadow' => 'none',
						'x_label_angle'=> 0,
						'y_label_left' => get_string('percentexceeding','scormreport_scoredistribution'),
						'x_label' => get_string('score','scormreport_scoredistribution'),
						'y_min_left' => 0,
						'y_max_left' => max($y_data),
						'y_axis_gridlines' => 21,
						'x_axis_gridlines' => 10,
						'xDecimal' => 0
					));
					echo draw_graph($chart);
	?></div>
<?php
                    $columns = array('interaction', 'type', 'mean', 'max', 'results');
                    $headers = array(
                        get_string('interaction', 'scormreport_scoredistribution'),
                        get_string('type', 'scormreport_scoredistribution'),
						get_string('mean', 'scormreport_scoredistribution'),
						get_string('max', 'scormreport_scoredistribution'),
						get_string('results', 'scormreport_scoredistribution')
					);

                    // Format data for tables and generate output.
                    $formatted_data = array();
					if (!empty($tabledata)) {
						echo $OUTPUT->heading(get_string('interactionssummary','scormreport_scoredistribution'),3);
						$table = new flexible_table('mod-scorm-score-distribution-report-'.$sco->id);

						$table->define_columns($columns);
						$table->define_headers($headers);
						$table->define_baseurl($PAGE->url);

						$table->setup();


						foreach ($tabledata as $interaction => $rowinst) {
							$sum = $rowinst['result'];
							ksort($sum);
							$barchart = bar_chart(array_keys($sum), array_values($sum),array('x_label'=>get_string('result','scormreport_scoredistribution'),'y_label_left'=>get_string('frequencypercent','scormreport_scoredistribution'), 'title' => ""));

							$table->add_data(array(
								$rowinst['id'],
								$rowinst['type'],
								number_format(freq_mean($sum),2,'.',''),
								max(array_keys($sum)),
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
