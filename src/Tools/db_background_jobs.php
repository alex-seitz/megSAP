<?php
/** 
	@page db_background_jobs
*/

require_once(dirname($_SERVER['SCRIPT_FILENAME'])."/../Common/all.php");

error_reporting(E_ERROR | E_WARNING | E_PARSE | E_NOTICE);

//parse command line arguments
$parser = new ToolBase("db_background_jobs", "Runs jobs in the the backgound in SGE whenever compute resources are free.");
$parser->addInfile("commands", "Input text file containing one command per line.", false);
$parser->addInt("slots_per_job", "Number of SGE slots (i.e. threads) to use per command.", false);
//optional
$parser->addFloat("max_slots", "Maximum percentage of SGE slots to use.", true, 80.0);
$parser->addInt("max_jobs", "Maximum number of jobs to start in parallel.", true, 1000000);
$parser->addInt("sleep_secs", "Number of seconds to sleep between tries to start jobs.", true, 120);
$parser->addString("queues", "Comma-separted list of SGE queues to use (is unset the default queues from the INI file are use).", true, "");
extract($parser->parse($argv));

//determine how many jobs are still running
function running_jobs($job_id_cache_file)
{
	//get running job IDs
	$running_ids = [];
	list($stdout) = exec2("qstat -u '*'");
	foreach($stdout as $line)
	{
		$line = trim($line);
		if ($line=="" || starts_with($line, "---") || starts_with($line, "job-ID")) continue;
		list($job_id) = explode(" ", $line, 2);
		$running_ids[$job_id] = true;
	}
	
	$output = 0;
	if (file_exists($job_id_cache_file))
	{
		$h = fopen2($job_id_cache_file, 'r');
		while(!feof($h))
		{
			$line = trim(fgets($h));
			if ($line=="") continue;
			list($job_id) = explode("\t", $line, 2);
			if (isset($running_ids[$job_id])) ++$output;
		}
		fclose($h);
	}
	return $output;
}


//init
$user = trim(exec('whoami'));
if ($queues=="") $queues = get_path("queues_default");
$queues = explode(",", $queues);

//determine job ID cache file
$job_id_cache_file = sys_get_temp_dir()."/db_background_jobs_{$user}_".strtr(realpath($commands), ["/"=>"_"]).".txt";

//load commands
$file = file($commands);
$commands = array();
foreach($file as $line)
{
	$line = trim($line);
	if ($line=="")  continue;
	$commands[] = $line;
}

while(count($commands)>0)
{
	print date("Y-m-d h:i:s")."\n";
	
	//determine slots
	$slots_overall = 0;
	$slots_used = 0;
	foreach($queues as $queue)
	{
		list($stdout) = exec2("qstat -u '*' -f | grep {$queue}");
		foreach($stdout as $line)
		{
			$line = preg_replace('/\s+/', ' ', $line);
			$line = explode(" ", $line);
			// skip queues which are in any error/warning state (additional column)
			if ((count($line) > 5) && (trim($line[5]) != "")) continue; 
			list(, $used, $overall) = explode("/", $line[2]);
			$slots_overall += $overall;
			$slots_used += $used;
		}
	}

	$slots_max_used = (int)($max_slots/100.0*$slots_overall);
	$slots_free = $slots_max_used - $slots_used;
	$running_jobs = running_jobs($job_id_cache_file);
	print "  Slots - overall:{$slots_overall} used:{$slots_used} max usable:{$slots_max_used} available:{$slots_free} - running:{$running_jobs}\n";
	if ($slots_free < $slots_per_job)
	{
		print "  No slots available\n";
	}
	else if ($running_jobs>=$max_jobs)
	{
		print "  Maximum number of jobs reached ($max_jobs)\n";
	}
	else
	{
		$id = 0;
		while ($slots_free>=$slots_per_job && $running_jobs<$max_jobs)
		{
			++$id;
			
			$command = trim(array_shift($commands));
			if ($command=="") break; // happens after last command
			
			print "  Starting command: {$command}\n";
			if (contains($command, "megSAP/src/NGS/db_queue_analysis.php")) //queue via NGSD
			{
				exec2($command);
			}
			else //submit to SGE directly
			{
				$sge_folder = get_path("data_folder")."/sge/background_jobs/";
				$base = "{$sge_folder}".date("Ymdhis")."_".str_pad($id, 3, '0', STR_PAD_LEFT)."_{$user}";
				$sge_out = "{$base}.out";
				$sge_err = "{$base}.err";
				$command_sge = "qsub -V -pe smp {$slots_per_job} -b y -wd {$sge_folder} -m n -M ".get_path("queue_email")." -e {$sge_err} -o {$sge_out} -q ".implode(",", $queues)." -shell n";
				list($stdout, $stderr) = exec2($command_sge." ".$command);
				
				$sge_id = explode(" ", $stdout[0])[2];
				print "    SGE job id: {$sge_id}\n";
				print "    SGE stdout: {$sge_out}\n";
				print "    SGE stderr: {$sge_err}\n";
				
				file_put_contents($job_id_cache_file, "{$sge_id}\t{$sge_out}\t{$sge_err}\n", FILE_APPEND);
			}
			++$running_jobs;
			$slots_free -= $slots_per_job;
		}
		print "  Commands remaining: ".count($commands)."\n";
	}
	
	if (count($commands)==0) break;
	sleep($sleep_secs);
}

?>