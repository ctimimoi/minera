<?php
/*
 * Utit_model
 * Utility model for minera
 *
 * @author michelem
 */
class Util_model extends CI_Model {

	public function __construct()
	{
		// load CPUMiner Model
		// TODO Switch model for CGMiner/BFGMiner
		$this->load->model('cpuminer_model', 'miner');
		
		parent::__construct();
	}

	/*
	//
	// Stats related stuff
	//
	*/
	
	// Get the live stats from cpuminer
	public function getStats()
	{
		if ($this->isOnline())
		{
			$a = $this->miner->callMinerd();

			if (is_object($a))
			{
				$a = (array)$a;
				
				// Add sysload stats
				$a["sysload"] = sys_getloadavg();
				
				// Add pools
				foreach ($a['pools'] as $pool)
				{
					$pool->alive = $this->checkPool($pool->url);
				}
				
				// Add controller temp
				$a["temp"] = $this->checkTemp();
				
				// Encode and save the latest
				$o = json_encode($a);
				$this->redis->set("latest_stats", $o);
				
				return $o;
			}
			else
			{
				if ($latestSaved = $this->redis->get("latest_stats"))
				{
					return $latestSaved;
				}
				else
				{
					return json_encode(array("error" => true));
				}
			}			
		}
		else
		{
			return json_encode(array("notrunning" => true));
		}
		
		return false;
	}
	
	/*
	// Parse the CPUMiner stats to get them per device instead per chip
	// with a summary total and active pool
	*/
	public function getParsedStats($stats)
	{
		$stats = json_decode($stats);

		$d = 0; $tdevice = array(); $tdfrequency = 0; $tdaccepted = 0; $tdrejected = 0; $tdhwerrors = 0; $tdshares = 0; $tdhashrate = 0;
		
		if (isset($stats->pools))
		{
			foreach ($stats->pools as $pool)
			{
				if ($pool->active == 1)
				{
					foreach($pool->stats as $session)
					{
						if ($session->stats_id == $pool->stats_id)
						{
							// Calculate pool hashrate
							$poolHashrate = round(65536.0 * ($session->shares / (time() - $session->start_time)), 0);
						}
					}
					$tdevice['pool']['hashrate'] = $poolHashrate;
					$tdevice['pool']['url'] = $pool->url;
					$tdevice['pool']['alive'] = $pool->alive;
				}
			}
		}
		
		if (isset($stats->devices))
		{
			foreach ($stats->devices as $name => $device)
			{
				$d++; $c = 0; $tcfrequency = 0; $tcaccepted = 0; $tcrejected = 0; $tchwerrors = 0; $tcshares = 0; $tchashrate = 0;
				foreach ($device->chips as $chip)
				{
					$c++;
					$tcfrequency += $chip->frequency;
					$tcaccepted += $chip->accepted;
					$tcrejected += $chip->rejected;
					$tchwerrors += $chip->hw_errors;
					$tcshares += $chip->shares;
					$tchashrate += $chip->hashrate;
				}
				$tdevice['devices'][$name]['frequency'] = round(($tcfrequency/$c), 0);
				$tdevice['devices'][$name]['accepted'] = $tcaccepted;
				$tdevice['devices'][$name]['rejected'] = $tcrejected;
				$tdevice['devices'][$name]['hw_errors'] = $tchwerrors;
				$tdevice['devices'][$name]['shares'] = $tcshares;
				$tdevice['devices'][$name]['hashrate'] = $tchashrate;
				
				$tdfrequency += $tdevice['devices'][$name]['frequency'];
				$tdaccepted += $tdevice['devices'][$name]['accepted'];
				$tdrejected += $tdevice['devices'][$name]['rejected'];
				$tdhwerrors += $tdevice['devices'][$name]['hw_errors'];
				$tdshares += $tdevice['devices'][$name]['shares'];
				$tdhashrate += $tdevice['devices'][$name]['hashrate'];
			}
			
			$tdevice['totals']['frequency'] = round(($tdfrequency/$d), 0);
			$tdevice['totals']['accepted'] = $tdaccepted;
			$tdevice['totals']['rejected'] = $tdrejected;
			$tdevice['totals']['hw_errors'] = $tdhwerrors;
			$tdevice['totals']['shares'] = $tdshares;
			$tdevice['totals']['hashrate'] = $tdhashrate;
			
			return json_encode($tdevice);
		}
		
		return false;
	}

	// Get the stored stats from Redis
	public function getStoredStats($seconds = 3600, $startTime = false)
	{	
		$current = ($startTime) ? $startTime : time();
		$time = $current-$seconds;

		$o = $this->redis->command("ZRANGEBYSCORE minerd_delta_stats $time $current");
		
		return $o;
	}
	
	// Get the stored stats from Redis
	public function getHistoryStats($type = "hourly")
	{	
		switch ($type)
		{
			case "hourly":
				$period = 300;
				$range = 12;
			break;
			case "daily":
				$period = 3600;
				$range = 24;
			break;
			case "weekly":
				$period = 3600*24;
				$range = 7;
			break;
			case "monthly":
				$period = 3600*24;
				$range = 30;
			break;
			case "yearly":
				$period = 3600*24*30;
				$range = 12;
			break;
		}
		
		$items = array();
		for ($i=0;$i<=($range*$period);$i+=$period)
		{
			$statTime = (time()-$i);
			$item = json_decode($this->avgStats($period, $statTime));
			if ($item)
				$items[] = $item;
		}
		
		$o = json_encode($items);
		
		return $o;
	}
	
	public function avgStats($seconds = 900, $startTime = false)
	{
		$records = $this->getStoredStats($seconds, $startTime);
		
		$i = 0; $timestamp = 0; $poolHashrate = 0; $hashrate = 0; $frequency = 0; $accepted = 0; $errors = 0; $rejected = 0; $shares = 0;
		
		if (count($records) > 0)
		{
			foreach ($records as $record)
			{
				$i++;
				$obj = json_decode($record);
				$timestamp += (isset($obj->timestamp)) ? $obj->timestamp : 0;
				$poolHashrate += (isset($obj->pool_hashrate)) ? $obj->pool_hashrate : 0;
				$hashrate += (isset($obj->hashrate)) ? $obj->hashrate : 0;
				$frequency += (isset($obj->avg_freq)) ? $obj->avg_freq : 0;
				$accepted += (isset($obj->accepted)) ? $obj->accepted : 0;
				$errors += (isset($obj->errors)) ? $obj->errors : 0;
				$rejected += (isset($obj->rejected)) ? $obj->rejected : 0;
				$shares += (isset($obj->shares)) ? $obj->shares : 0;
			}
			
			$timestamp = round(($timestamp/$i), 0);
			$poolHashrate = round(($poolHashrate/$i), 0);
			$hashrate = round(($hashrate/$i), 0);
			$frequency = round(($frequency/$i), 0);
			$accepted = round(($accepted/$i), 0);
			$errors = round(($errors/$i), 0);
			$rejected =round(($rejected/$i), 0);
			$shares =round(($shares/$i), 0);
		}

		$o = false;
		if ($timestamp)
		{
			$o = array(
				"timestamp" => $timestamp,
				"seconds" => $seconds,
				"pool_hashrate" => $poolHashrate,
				"hashrate" => $hashrate,
				"frequency" => $frequency,
				"accepted" => $accepted,
				"errors" => $errors,
				"rejected" => $rejected,
				"shares" => $shares
			);
		}
		
		return json_encode($o);
		
	}
	
	// Store the live stats on Redis
	public function storeStats()
	{
		log_message('error', "Storing stats...");
		
		$data = json_decode($this->getParsedStats($this->getStats()));

		$ph = (isset($data->pool->hashrate)) ? $data->pool->hashrate : 0;
		$dh = (isset($data->totals->hashrate)) ? $data->totals->hashrate : 0;
		$fr = (isset($data->totals->frequency)) ? $data->totals->frequency : 0;		
		$ac = (isset($data->totals->accepted)) ? $data->totals->accepted : 0;		
		$hw = (isset($data->totals->hw_errors)) ? $data->totals->hw_errors : 0;
		$re = (isset($data->totals->rejected)) ? $data->totals->rejected : 0;
		$sh = (isset($data->totals->shares)) ? $data->totals->shares : 0;
								
		// Get totals
		$o = array(
			"timestamp" => time(),
			"pool_hashrate" => $ph,
			"hashrate" => $dh,
			"avg_freq" => $fr,
			"accepted" => $ac,
			"errors" => $hw,
			"rejected" => $re,
			"shares" => $sh
		);

		// Get latest
		$latest = $this->redis->command("ZREVRANGE minerd_totals_stats 0 0");
		$lf = 0; $la = 0; $le = 0; $lr = 0; $ls = 0;
		if ($latest)
		{
			$latest = json_decode($latest[0]);
			$lf = $latest->avg_freq;
			$la = $latest->accepted;
			$le = $latest->errors;
			$lr = $latest->rejected;
			$ls = $latest->shares;
		}

		// Get delta current-latest
		$delta = array(
			"timestamp" => time(),
			"pool_hashrate" => $ph,
			"hashrate" => $dh,
			"avg_freq" => max((int)($fr - $lf), 0),
			"accepted" => max((int)($ac - $la), 0),
			"errors" => max((int)($hw - $le), 0),
			"rejected" => max((int)($re - $lr), 0),
			"shares" => max((int)($sh - $ls), 0)
		);
		
		// Store delta
		$this->redis->command("ZADD minerd_delta_stats ".time()." ".json_encode($delta));			
		
		log_message('error', "Delta Stats stored as: ".json_encode($delta));
		
		// Store totals
		$this->redis->command("ZADD minerd_totals_stats ".time()." ".json_encode($o));
		
		log_message('error', "Total Stats stored as: ".json_encode($o));
	}
	
	function setPools($pools)
	{
		return $this->redis->set("minerd_pools", json_encode($pools));
	}

	function getPools()
	{
		return $this->redis->get("minerd_pools");
	}
	
	function setCommandline($string)
	{
		return $this->redis->set("minerd_settings", $string);
	}

	function getCommandline()
	{
		return $this->redis->get("minerd_settings");
	}
		
	/*
	//
	// Crypto rates related stuff
	//
	*/
	
	// Get Bitstamp API to look at BTC/USD rates
	public function getBtcUsdRates()
	{
		if ($json = @file_get_contents("https://www.bitstamp.net/api/ticker/"))
		{
			$a = json_decode($json);
			return $a;
		}
		else
		{
			return false;
		}
	}
	
	// Get Cryptsy API to look at LTC/BTC rates
	public function getCryptsyRates($id)
	{
		if ($json = @file_get_contents("http://pubapi.cryptsy.com/api.php?method=singlemarketdata&marketid=$id"))
		{
			$a = json_decode($json);
			return $a;
		}
		else
		{
			return false;
		}
	}
	
	
	/*
	//
	// Miner and System related stuff
	//
	*/
		
	// Check if pool is alive
	public function checkPool($url)
	{	
		$parsedUrl = @parse_url($url);

		if (isset($parsedUrl['host']) && isset($parsedUrl['port']))
		{
			$conn = @fsockopen($parsedUrl['host'], $parsedUrl['port'], $errno, $errstr, 1);
			if (is_resource($conn))
			{
				fclose($conn);
				return true;
			}
		}
		
		return false;
	}	
	
	// Check if the minerd if running
	public function isOnline()
	{
		if(!($fp = @fsockopen("127.0.0.1", 4028, $errno, $errstr, 1)))
		{
			return false;
		}
		
		return true;
	}
	
	// Check RPi temp
	public function checkTemp()
	{
		if (file_exists($this->config->item("rpi_temp_file")))
		{
			$temp = exec("cat ".$this->config->item("rpi_temp_file"));
			return number_format($temp/1000, 2);
		}
		else
		{
			return false;
		}
	}
	
	public function checkMinerIsUp()
	{		
		// Check if miner is not manually stopped
		if ($this->redis->get("minerd_status"))
		{
			if ($this->isOnline() === false)
			{
				log_message('error', "It seems miner is down, trying to restart it");
				// Force stop and killall
				$this->minerStop();
				// Restart miner
				$this->minerStart();
			}
			
			log_message('error', "Miner is up");
		}
		
		return;
	}
	
	// Call shutdown cmd
	public function shutdown()
	{
		log_message('error', "Shutdown cmd called");
		
		exec("sudo shutdown -h now");

		return true;
	}

	// Call reboot cmd
	public function reboot()
	{
		log_message('error', "Reboot cmd called");
		
		exec("sudo reboot");

		return true;
	}
	
	// Write rc.local startup file
	public function saveStartupScript($delay = 5, $extracommands = false)
	{
		$command = array($this->config->item("screen_command"), $this->config->item("minerd_command"), $this->getCommandline());
		
		$rcLocal = file_get_contents(FCPATH."rc.local.minera");
		
		$rcLocal .= "\nsleep $delay\nsu - ".$this->config->item('system_user').' -c "'.implode(' ', $command)."\"\n$extracommands\nexit 0";
		
		file_put_contents('/etc/rc.local', $rcLocal);
		
		log_message('error', "Startup script saved: ".var_export($rcLocal, true));

		return true;
	}

	public function saveCurrentFreq()
	{
		return $this->miner->saveCurrentFreq();
	}

	public function selectPool($poolId)
	{
		return $this->miner->selectPool($poolId);
	}
			
	// Stop miner
	public function minerStop()
	{
		return $this->miner->stop();
	}
	
	// Start miner
	public function minerStart()
	{
		$this->resetCounters();		
		return $this->miner->start();
	}
	
	// Stop minerd
	public function minerRestart()
	{
		$this->resetCounters();
		return $this->miner->restart();
	}

	// Save a fake last data to get correct next delta
	public function resetCounters()
	{
		$reset = array(
			"timestamp" => time(),
			"pool_hashrate" => 0,
			"hashrate" => 0,
			"avg_freq" => 0,
			"accepted" => 0,
			"errors" => 0,
			"rejected" => 0,
			"shares" => 0
		);
		
		// Reset the counters
		$this->redis->command("ZADD minerd_totals_stats ".time()." ".json_encode($reset));
	}
		
	// Call update cmd
	public function update()
	{
		$lines = array();
		// Pull the latest code from github
		exec("cd ".FCPATH." && sudo -u " . $this->config->item("system_user") . " sudo git fetch --all && sudo git reset --hard origin/master", $out);
		
		$logmsg = "Update request from ".$this->currentVersion()." to ".$this->redis->command("HGET minera_update new_version")." : ".var_export($out, true);
		
		$lines[] = $logmsg;
		
		log_message('error', $logmsg);
				
		// Run upgrade script
		exec("cd ".FCPATH." && sudo -u " . $this->config->item("system_user") . " sudo ./upgrade_minera.sh", $out);

		$logmsg = "Running upgrade script".var_export($out, true);

		$lines[] = $logmsg;
				
		log_message('error', $logmsg);
			
		$this->redis->del("minera_update");
		$this->redis->del("minera_version");
		$this->checkUpdate();
		
		$logmsg = "End Update";
		$lines[] = $logmsg;
		log_message('error', $logmsg);
		
		return json_encode($lines);
	}
	
	// Check Minera version
	public function checkUpdate()
	{
		// wait 1h before recheck
		if (time() > ($this->redis->command("HGET minera_update timestamp")+3600))
		{
			log_message('error', "Checking Minera updates");
			
			$this->redis->command("HSET minera_update timestamp ".time());

			$latestConfig = json_decode(file_get_contents($this->config->item("remote_config_url")));

			$localVersion = $this->currentVersion();
			$this->redis->command("HSET minera_update new_version ".$latestConfig->version);

			if ($latestConfig->version != $localVersion)
			{
				log_message('error', "Found a new Minera update");

				$this->redis->command("HSET minera_update value 1");
				return true;
			}
		
			$this->redis->command("HSET minera_update value 0");			
		}
		else
		{
			if ($this->redis->command("HGET minera_update value"))
				return true;
			else
				return false;
		}
	}

	// Get local Minera version
	public function currentVersion()
	{
		// wait 1h before recheck
		if (time() > ($this->redis->command("HGET minera_version timestamp")+3600))
		{
			$this->redis->command("HSET minera_version timestamp ".time());
			$localConfig = json_decode(file_get_contents(base_url('minera.json')));		
			$this->redis->command("HSET minera_version value ".$localConfig->version);
			return $localConfig->version;
		}
		else
		{
			return $this->redis->command("HGET minera_version value");
		}
	}
	
	/*
	// Call the Mobileminer API to send device stats
	*/
	public function callMobileminer()
	{
		if ($this->redis->get("mobileminer_enabled"))
		{
			if ($this->redis->get("mobileminer_system_name") && $this->redis->get("mobileminer_email") && $this->redis->get("mobileminer_appkey"))
			{
				$rawStats = json_decode($this->getStats());
				$stats = json_decode($this->getParsedStats(json_encode($rawStats)));
								
				$params = array("emailAddress" => $this->redis->get("mobileminer_email"), "applicationKey" => $this->redis->get("mobileminer_appkey"), "apiKey" => $this->config->item('mobileminer_apikey'), "detailed" => true);
				
				$poolUrl = (isset($stats->pool->url)) ? $stats->pool->url : "no pool configured";
				$poolStatus = (isset($stats->pool->alive) && $stats->pool->alive) ? "Alive" : "Dead";
								
				$i = 0; $data = array();
				if (count($stats->devices) > 0)
				{
					foreach ($stats->devices as $devName => $device)
					{
						$data[] = array(
							"MachineName" => $this->redis->get("mobileminer_system_name"),
							"MinerName" => "Minera",
							"CoinSymbol" => "",
							"CoinName" => "",
							"Algorithm" => "Scrypt",
							"Kind" => "Asic",
							"Name" => $devName,
							"FullName" => $devName,
							"PoolIndex" => 0,
							"PoolName" => $poolUrl,
							"Index" => $i,                                            
							"DeviceID" => $i,
							"Enabled" => $this->isOnline(),
							"Status" => $poolStatus,
							"Temperature" => false,
							"FanSpeed" => 0,
							"FanPercent" => 0,
							"GpuClock" => 0,
							"MemoryClock" => 0,
							"GpuVoltage" => 0,
							"GpuActivity" => 0,
							"PowerTune" => 0,
							"AverageHashrate" => round(($device->hashrate/1000), 0),
							"CurrentHashrate" => round(($device->hashrate/1000), 0),
							"AcceptedShares" => $device->accepted,
							"RejectedShares" => $device->rejected,
							"HardwareErrors" => $device->hw_errors,
							"Utility" => false,
							"Intensity" => null,
							"RejectedSharesPercent" => round(($device->rejected*100/($device->accepted+$device->rejected+$device->hw_errors)), 3),
							"HardwareErrorsPercent" => round(($device->hw_errors*100/($device->accepted+$device->rejected+$device->hw_errors)), 3)
						);
						$i++;
					}
				}
		
				$data_string = json_encode($data);
				
				//log_message('error', $data_string);
				log_message('error', "Sending data to Mobileminer");
				
				$ch = curl_init($this->config->item('mobileminer_url_stats')."?".http_build_query($params));

				curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
				curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($ch, CURLOPT_HTTPHEADER, array(
				    'Content-Type: application/json',
				    'Content-Length: ' . strlen($data_string))
				);
				 
				$result = curl_exec($ch);
				
				curl_close($ch);
				
				log_message('error', var_export($result, true));
				
				return $result;
				
			}
		}
		
		return false;
	}
	
	// Check Internet connection
	public function checkConn()
	{
		if(!fsockopen("www.google.com", 80)) {
			return false;
		}
		
		return true;
	}

	// Socket server to get a fake miner to do tests
	public function fakeMiner()
	{
		$server = stream_socket_server("tcp://127.0.0.1:1337", $errno, $errorMessage);
		
		if ($server === false) {
		    throw new UnexpectedValueException("Could not bind to socket: $errorMessage");
		}
		
		for (;;) {
		    $client = @stream_socket_accept($server);
		
		    if ($client) 
		    {    
		    	// Inject stdin
		        //stream_copy_to_stream($client, $client);
		        
		        // Inject some json
		        $j = file_get_contents('test.json');
		        fwrite($client, $j);
		        
		        fclose($client);
		    }
		}
	}
}
