<?
/*
Filename: classes.php

Method: INCLUDE
Output: INCLUDE

Description: Master class object file that holds all!

*/

// the class home! The mothership.
class site {
	var $id;
	var $url;

	var $server;
	var $server_id;
	var $server_ip;
	var $ip; // shorthand access to $server_ip
	var $ssl; // true or false

	var $name;
	var $version;
	var $creator;
	var $creator_id;
	var $creator_email;
	var $create_date;
	var $modify_date;

	var $cpanel;

	var $captcha_public_key;
	var $captcha_private_key;

	var $google_maps_api_key;

	// merely for admin purposes & seeing who uses it well.
	
	// the following has to do with our analytics data API integration. 
	var $google_analytics_session_token;
	var $google_analytics_id; // the gmail account ID for analytics (umbrella)
	var $google_analytics; // The UA-XXX whatever they entered. Has nothing to do with our API connection... just the site.
	var $google_analytics_profile_id; // profile (view) id of the actual API integration.

	var $parentid = false;
	var $children = false;
	var $active;

	var $rets;// true false boolean of whehter MLS sync is enabled or not!
	var $rets_id;
	var $rets_url;

	var $mlssync_last_create_date;

	var $logs_url; // false for non-existent (unlikely) and full URL if there is one.

	function initialize($id, $sitearray = false) {
		global $mysql_time;
		global $settings;
		global $local;
		global $mysqli;

		$data = false;

		if(is_array($GLOBALS[BRIX_SITES_CACHE]) && array_key_exists($id, $GLOBALS[BRIX_SITES_CACHE])) {
			$data = $GLOBALS[BRIX_SITES_CACHE][$id];

		} else if($sitearray===false) {
			// default way of querying based on input of home ID.
			// the get_properties() function is designed to get enough information to show the list of listings (listing gallery) for display, so even though it may be tempting to replace the below query with another iteration of get_properties('', $query['id'],...);, DONT DO IT. - JEff 2017-09-20
			$singlquery = array('id'=>$id);
			$rows = get_sites($singlquery, '', '', '', '', false, true);
			if($rows) {
				$data = $rows[$id]; // first and only row based on ID result.
			} else {
				$data = false;
			}
		} else if(is_array($sitearray)) {
			// retrieve from the $data array - the get_properties function already returned a lot of the fields to speed it up, this reduces query time for map searches.
			$data = $sitearray;// feed $homearray into $data then start processing the data only, bypass the MySQL query.
		}

		if($data!==false) {
			foreach($data as $key=>$value) {
				$this->data[$key] = $value;
			}

			$this->id = $data['id'];

			$this->url = ($data['url']!='' ? $data['url'] : false);
			if(!preg_match('/\/$/', $this->url)) {
				// make sure / is added to URLs.
				$this->url = $this->url.'/';
			}

			if(preg_match('/^https/', $this->url)==1) {
				$this->ssl = true;
			} else {
				$this->ssl = false;
			}

			$this->domain = get_host($this->url);

			$this->name = $data['name'];
			$this->version = remove_all_but_numbers($data['version'])*1; // force number.

			$this->active = ($data['active']==1 ? true : false);
			$this->parentid = ($data['parentid']!='' ? $data['parentid'] : false);

			// $this->get_children();

			$this->server_id = ($data['server_id']!=0 ? $data['server_id'] : false);
			$this->server_ip = ($data['server_id']!=0 ? $data['server_ip'] : false);
			$this->ip = $this->server_ip; // shorthand access to $server_ip.

			if($data['rets']==0) {
				$this->rets = false; // no MLS runs!
				$this->rets_id =	false;
				$this->rets_url = false;
			} else {
				$this->rets = true;
				$this->rets_id = $data['rets'];
				$this->rets_url = $data['rets_url'];
			}
			// cpanel_username and other strings. Return blanks, not false, if not there...
			$this->cpanel = $data['cpanel'];
			$this->google_maps_api_key = $data['google_maps_api_key'];
			$this->google_analytics_session_token = json_decode($data['google_analytics_session_token'], TRUE);

			$this->google_analytics_id = $data['google_analytics_id']; // account ID, holds multiple sub-properties.
			$this->google_analytics = $data['google_analytics'];
			$this->google_analytics_profile_id = $data['google_analytics_profile_id']; // actual view ID under specific analytics profile.

			$this->captcha_public_key = $data['captcha_public_key'];
			$this->captcha_private_key = $data['captcha_private_key'];

			$this->create_date_timestamp = $data['create_date'];
			$this->modify_date_timestamp = $data['modify_date'];

			$this->create_date = date($mysql_time['full'],$data['create_date']);
			$this->modify_date = date($mysql_time['full'],$data['modify_date']);

			$this->creator = $data['creator_id']; // for now, ID is fine.. do we need an object? don't think so.
			$this->creator_id = $data['creator_id'];
			$this->creator_email = $data['creator_email'];

			$this->mlssync_last_create_date = ($data['mlssync_last']=='0' ? false : $data['mlssync_last'] );

			$this->logs_url = $settings['sitepath'].'admin/logs/'.$this->domain.'.log';
			return $this->id;
		} else {
			$this->id = false;
			return false;
		}
	}

	function get_children($params=false) {
		$children = false; // default, false!
		if($params!==false) {
			// if added params to be queried... most likely not.
			$query = param_to_query(($params));
		} else {
			$query = array();
		}

		if(is_array($GLOBALS[BRIX_SITES_CACHE]) && count($GLOBALS[BRIX_SITES_CACHE])>10) {
			foreach($GLOBALS[BRIX_SITES_CACHE] as $sites) {
				if($sites['parentid']==$this->id) {
					$children[] = $sites['id'];
				}
			}
		} else {
			// couldn't run off cache, so do a live query.
			$query['parentid'] = $this->id;

			$children = get_sites($query, '', '', '', '', false, false);
		}
		// add this ID!

		$this->children = $children; // this will be false if there are no sub-sites.
		return $children;
	}

	/**
	 * Checks which server the website is on, and sets $this->server_ip to the IP address,
	 * and also returns the IP address.
	 * Returns FALSE on failed check, if it doesn't point to any of our servers.
	 * @return bool|string
	 */
	function get_server_ip() {
		// fetch the remote server address to verify which server this is on for record keeping.
		global $settings;
		global $mysqli;
		global $handle;

		$ip = gethostbyname(parse_url($this->url, PHP_URL_HOST));
		$isValid = filter_var($ip, FILTER_VALIDATE_IP);
		if($isValid) {
			// only run if the IP fetch was successful.
			// if gethostbyname() fails, it just returns the URL as-is, not a boolean false!
			if($ip!=$this->server_ip && ($this->server_id > 0 || $this->server_id==false)) {
				// mismatch - update!
				$server_id = get_server_by_ip($ip);
				if($server_id==false) {
					$server_id = 0; // default to 0 which should return a red big fucking warning on UI for bad IP.
					site_error_log('No longer on our server, found on '.$ip.'!');
					$ip = false;


				} else {
					site_error_log('Correction to newer server ID: '.$server_id.'.');

				}
				$updatesql = "UPDATE `sites` SET `server`='".$server_id."' WHERE id='".$this->id."'";
				$mysqli->query($updatesql);
			} else {
				$ip = $this->server_ip; // return as-is, matching server IP!
			}
		} else {
			// remote fetch failed, so assume that whatever IP we have is correct.. cannot override on a failed connection.
			$ip = $this->server_ip; // return as-is, matching server IP!
		}

		return $ip;
	}


	function get_keys() {
		global $mysqli;
		$keysurl = $this->url.'/admin/api/report_keys.php?hash=58300774';
		list($keysscript_raw, $http_code) = curl_get_file_contents($keysurl,'GET', '', 100000);
		$this->get_forwarded_destination(); // do a check of forwarding, SSL etc.

		$keysscript = api_export_decrypt($keysscript_raw);
		$keysjson = json_decode($keysscript, true);
		if($keysjson!==false) {
			$return = true; // set it to true to start. If SQL fails, then return false.
			if($keysjson['cpanel_username']!=$this->cpanel) {
				$updatesql = "UPDATE `sites` SET `cpanel`='".$keysjson['cpanel_username']."' WHERE id='".$this->id."'";
				$mysqli->query($updatesql);
				$this->cpanel = $keysjson['cpanel_username'];
				site_error_log('Update cpanel to '.$keysjson['cpanel_username'].'.');
			}

			if($keysjson['google_maps_api_key']!=$this->google_maps_api_key) {
				$updatesql = "UPDATE `sites` SET `google_maps_api_key`='".$keysjson['google_maps_api_key']."' WHERE id='".$this->id."'";
				$mysqli->query($updatesql);
				$this->google_maps_api_key = $keysjson['google_maps_api_key'];
				site_error_log('Update google_maps_api_key from '.$this->google_maps_api_key.' to '.$keysjson['google_maps_api_key'].'.');
			}

			if($keysjson['google_analytics']!=$this->google_analytics) {
				$updatesql = "UPDATE `sites` SET `google_analytics`='".$keysjson['google_analytics']."' WHERE id='".$this->id."'";
				$mysqli->query($updatesql);
				$this->google_analytics = $keysjson['google_analytics'];
				site_error_log('Update google_analytics to '.$keysjson['google_analytics'].'.');
			}

			if($keysjson['captcha_public_key']!=$this->captcha_public_key) {
				$updatesql = "UPDATE `sites` SET `captcha_public_key`='".$keysjson['captcha_public_key']."' WHERE id='".$this->id."'";
				$mysqli->query($updatesql);
				$this->captcha_public_key = $keysjson['captcha_public_key'];
				site_error_log('Update captcha_public_key to '.$keysjson['captcha_public_key'].'.');
			}

			if($keysjson['captcha_private_key']!=$this->captcha_private_key) {
				$updatesql = "UPDATE `sites` SET `captcha_private_key`='".$keysjson['captcha_private_key']."' WHERE id='".$this->id."'";
				$mysqli->query($updatesql);
				$this->captcha_private_key = $keysjson['captcha_private_key'];
				site_error_log('Update captcha_private_key to '.$keysjson['captcha_private_key'].'.');
			}

		} else {
			// json decode failed. What gives?
			$return = false;
			site_error_log('Key fetch failed (http code: '.$http_code.') '. $keysscript_raw);
		}

		return $return;

	}


	function get_forwarded_destination() {
		global $mysqli;
		global $settings;

		// need to hit a URL to check.. might as well be the version.txt so it doesn't invoke a PHP call.
		$versionurl = $this->url;
		list($versionresult, $http_code, $curl_info) = curl_get_file_contents($versionurl,'GET', '', 100000, false, false);
		if($http_code!=200) {
			$redirect_url = $curl_info['redirect_url'];
		} else {
			$redirect_url = false;
		}
		if($redirect_url!==false) {
			$query = array(
				'url'=> get_host($redirect_url),
			);
			if(strpos($this->url, $query['url'])>0) {
				// same URL means it's forwarding to itself! Suspended site most likely.
				if(preg_match('/^3/', $http_code)!==false ) {
					// forwarding still with same URL - let's figure out SSL status.
					if(preg_match('/^https/', $redirect_url)==1 && preg_match('/^https/', $this->url)!=1) {
						// update URL to contain https://
						$newurl = 'https://'.$query['url'].'/';
						$updatesql = "UPDATE `sites` SET `url`='".$newurl."' WHERE id='".$this->id."'";
						$mysqli->query($updatesql);
						site_error_log('- Update to have SSL in URL: '.$newurl);
					} else if(preg_match('/^https/', $redirect_url)!=1 && preg_match('/^https/', $this->url)==1) {
						// thyey took SSL off... silly them, to save $10/mth. But. update nonetheless.
						$newurl = 'http://'.$query['url'].'/';
						$updatesql = "UPDATE `sites` SET `url`='".$newurl."' WHERE id='".$this->id."'";
						$mysqli->query($updatesql);
						site_error_log('- Update to NO SSL in URL: '.$newurl);
					}
				}
			} else {
				// redirected elsewhere, let's find out what's going on.
				$site = get_sites($query, '', '', '', '', false, true);
				if($site!==false) {
					site_error_log('Found parent site candidate matching URL '.$redirect_url.'!');
					if(count($site)==1) {
						// 1 result, perfect.
						foreach($site as $parentsite) {
							$parent = new site;
							$parent->initialize($parentsite['id'], $parentsite);
						}
						if($this->parentid!=$parent->id && $this->id!=$parent->id) {
							site_error_log('- Attach to parent site '.$parent->url.' ('.$parent->id.').');
							$this->set_hierarchy($parent->id);
						}

						if($this->active==true) {
							// forwarded URls do not need to update, MLS sync etc. to remove from ACTIVE status!
							site_error_log('- Deactivate since its forwarded.');
							$this->set_active(false);
						}
					}
				} else {
					if($this->parentid!=false) {
						site_error_log('Invalid parent found at '.$this->parentid.', disassociate!');
						$this->set_hierarchy(false);
					}

					if($this->active==true) {
						$this->set_active(false);
					}
					return $redirect_url; // return raw URL, not attached to parent ID yet.

				}
			}


		} else {
			if($this->active==false) {
				// forwarded URls do not need to update, MLS sync etc. to remove from ACTIVE status!
				site_error_log('- Set site to active.');
				$this->set_active(true);
			}
			return $this->id; // its own ID, means it's not forwarded.
		}
	}

	/**
	 * @param bool $parent Send ID of the parent site ID, or send false to detach.
	 *
	 */
	function set_hierarchy($parent = false) {
		// if parent is FALSE, detach it.
		// if parent is set to ID, do the obvious.
		global $settings;
		global $mysqli;
		global $handle;

		if($parent==false) {
			$updatesql = "DELETE FROM `hierarchy` WHERE `site`='".$this->id."'";
			site_error_log('Killing site parent - becoming independent.');
		} else {
			$parentsite = new site;
			if($parentsite->initialize($parent)) {
				// make sure we attach to a site that is a valid object.
				$updatesql = "INSERT INTO `hierarchy` (`site`, `parent`) VALUES ('".$this->id."', '".$parentsite->id."');";
				site_error_log('Placing under parent '.$parentsite->url.' ('.$parentsite->id.')');
			} else {
				$updatesql = false;
				site_error_log('Could not find parent at ID '.$parent.', cannot set as child site.');
			}
		}

		if($updatesql!==false) {
			$insertresult = $mysqli->query($updatesql);
			if($insertresult) {
				site_error_log('Insertion of parent/child row for  '.$this->id.' successful ('.$mysqli->insert_id.')');
				return true;
			} else {
				site_error_log('Failed to insert hierarchy row for '.$this->id.': '.$mysqli->error.'');
				return false;
			}
		} else {
			return false;
		}
	}

	function set_active($active = false) {
		global $mysqli;
		global $now;
		global $handle;

		$activevar = ($active===true ? 1 : 0);
		$deactivatesql = "UPDATE `sites` SET `active`=".$activevar.", `modify_date`='" . $now . "' WHERE id='" . $this->id . "'";
		$activeupdate = $mysqli->query($deactivatesql);
		if($activeupdate!==false) {
			return true;
		} else {
			return false;
		}

	}

	function run_upgrade($force = false) {
		global $settings;
		global $mysqli;
		global $handle; // pass through global var for $handle so that get_server_ip() and other sub-functions can log!
		global $now;
		global $local;

		$deactivate = false; // by default, set false.
		$serverip = $this->get_server_ip();
		// only run if server IP is good
		if($serverip!=false) {
			$upgradeurl = $this->url.'/upgrade.php?hash=58300774'.($force==true ? '&force=1' : '');
			list($upgradescript, $http_code) = curl_get_file_contents($upgradeurl,'GET', '', 100000, false, false);
			if($upgradescript!=false && strpos($upgradescript, '{')>0 && preg_match('/^2/', $http_code)!==false) {
				$upgradelines = explode("\n", $upgradescript);
				$success = false; // set to false to start.

				$successstring = '{success}';
				$uptodatestring = '{uptodate}';
				$failurestring = '{failure}';
				$versionstring = '{version}';
				foreach($upgradelines as $upgradeline) {
					if(strpos($upgradeline, $uptodatestring)!==false) {
						$success = true; // already up-to-date, so call it successful.
						site_error_log('Already up to date! ('.$upgradeline.')');
					} else if(strpos($upgradeline, $successstring)!==false) {
						$success = true; // found a successful line!
						list($throwaway, $message) = explode($successstring, $upgradeline);
						list($message, $throwaway) = explode('<br />', $message);
						site_error_log($message); // log the success messages. Zip successful, or SQL successful etc.
					} else if(strpos($upgradeline, $versionstring)!==false) {
						// echo "Caught upgrade version: ".$upgradeline."<br />\n";
						list($throwaway, $version) = explode($versionstring, $upgradeline);
						list($version, $throwaway) = explode('<br />', $version);
						$version = remove_all_but_numbers($version)*1; // force numeric.
						$updatesql = "UPDATE `sites` SET `version`=".$version.", `modify_date`='".$now."' WHERE id='".$this->id."'";
						$mysqli->query($updatesql);
						site_error_log('New version is '.$version.'.');
					} else if(strpos($upgradeline, $failurestring)!==false) {
						list($throwaway, $failure) = explode($failurestring, $upgradeline);
						list($failure, $throwaway) = explode('<br />', $failure);
						site_error_log('Failed: '.$failure.'.');
					}
				}
				if($success===true && $this->active===false) {
					$this->set_active(true);
				}
			} else {
				// connection to IP was fine but invalid file?
				$deactivate = true;
				site_error_log('Upgrade failed with code '.$http_code.' on IP '.$serverip."\n".$upgradescript);
				if(preg_match('/^3/', $http_code)) {
					$this->get_forwarded_destination();
				}
			}
		} else {
			// IP not one of our servers, don't even make the call!
			$success = false;
			if($this->active===true) {
				// our system thinks this site is active, set it to inactive.
				$deactivate = true;
			}
			site_error_log('Not upgrading as the IP returned ('.$serverip.', code '.$http_code.') was no good.');
		}
		if($deactivate===true) {
			$this->set_active(false);
			site_error_log('Set site to inactive!');
		}
		return $success;

	}

	function run_cacheclean() {
		global $settings;
		global $handle; // pass through global var for $handle so that get_server_ip() and other sub-functions can log!

		$cacheremoveurl = $this->url.'/admin/cron/cron_cacheremove.php?hash=58300774';
		list($cacheremovalscript, $http_code) = curl_get_file_contents($cacheremoveurl,'GET', '', 1000000);
		if($cacheremovalscript!=false && strpos($cacheremovalscript, '{deleted}')>0) {
			$cacheremovallines = explode("\n", $cacheremovalscript);
			$success = false; // set to false to start.
			$failurestring = '{failure}';
			$deletedstring = '{deleted}';
			foreach($cacheremovallines as $line) {
				if(strpos($line, $deletedstring)!==false) {
					// echo "Caught delete count: ".$line."<br />\n";
					$success = true;
					list($throwaway, $deleted) = explode($deletedstring, $line);
					list($deleted, $throwaway) = explode('<br />', $deleted);
					site_error_log('Cache clear: '.$deleted.'.');
				} else if(strpos($line, $failurestring)!==false) {
					list($throwaway, $failure) = explode($failurestring, $line);
					list($failure, $throwaway) = explode('<br />', $failure);
					site_error_log('Failed: '.$failure.'.');
				}
			}
		}
		return $success;
	}


	function run_mlssync($addonly=false) {
		global $settings;
		global $handle; // pass through global var for $handle so that get_server_ip() and other sub-functions can log!
		global $mysqli;
		global $mysql_time;
		global $now;

		$success = false; // set to false to start.
		$disabled = false;
		$failure = ''; // blank to start.
		$mlssyncurl = $this->url.'/cron/retsimport.php?hash=58300774'.($addonly==true ? '&addonly=1' : '');
		list($mlssyncscript, $http_code) = curl_get_file_contents($mlssyncurl,'GET', '', 100000);
		if($mlssyncscript!=false && strpos($mlssyncscript, 'cron')>0) {
			$mlssynclines = explode("\n", $mlssyncscript);
			$successstring = '{success}';
			$failurestring = '{failure}';
			$mlsurlstring = '{mlsurl}';
			$disabledstring = '{disabled}';
			foreach($mlssynclines as $line) {
				if(strpos($line, $disabledstring)!==false) {
					// MLS is disabled on this site. Set `rets`=0
					$updatesql = "UPDATE `sites` SET `rets`=0, `modify_date`='".$now."' WHERE id='".$this->id."'";
					$mysqli->query($updatesql);
					site_error_log('MLS disabled on '.$this->url.'.');
					$disabled = true;
				} else if(strpos($line, $successstring)!==false && $success===false) {
					$success = true; // found a successful line!
					site_error_log('MLS Sync script ran successfully.');
				} else if(strpos($line, $mlsurlstring)!==false) {
					// echo "Caught delete count: ".$line."<br />\n";
					list($throwaway, $mlsurl) = explode($mlsurlstring, $line);
					list($mlsurl, $throwaway) = explode('<br />', $mlsurl);
					site_error_log('Fetching from '.$mlsurl.'.');
					// if the MLS URL and this site's $mls_url does not match, update!!!
					$mlsdomain = get_host($mlsurl);
					if($this->mls_url!=$mlsurl) {
						$rets = get_rets(array('url'=>$mlsdomain));
						$retsid = key($rets);
						$updatesql = "UPDATE `sites` SET `rets`=".$retsid.", `modify_date`='".$now."' WHERE id='".$this->id."'";
						$mysqli->query($updatesql);
						site_error_log('RETS ID updated to '.$retsid.'.');
					}
				} else if(strpos($line, $failurestring)!==false) {
					list($throwaway, $failure) = explode($failurestring, $line);
					list($failure, $throwaway) = explode('<br />', $failure);
					site_error_log('Failed: '.$failure.'.');
				}

			}

			// log results into the table mlssync
			$entry = array(
				'site'=>$this->id,
				'rets'=>$this->rets_id,
				'create_date'=>date($mysql_time['full'],time()),
				'success' => ($success===true ? 1 : 0),
				'user'=> ($_COOKIE['userid']!='' ? $_COOKIE['userid'] : 0), // not fully secure in the sense that it doen't check for logged in duration & status. This cookie can stay alive longer. But this is just a cron job or run by a logged in user so doesn't really matter.
				'message'=>($success===false ? $failure : 'Success!')
			);

			form_to_table('mlssync', $entry);

			if($disabled!==true) {
				$success = $this->run_groupbuildings(); // if this fails, still should return false.
			}
		}
		return $success;
	}

	function run_groupbuildings() {
		global $settings;
		global $handle; // pass through global var for $handle so that get_server_ip() and other sub-functions can log!
		global $mysqli;
		global $now;
		$success = false; // set to false to start.

		$groupbuildingscurl = $this->url.'/cron/groupbuildings.php?hash=58300774';
		list($groupbuildingsscript, $http_code) = curl_get_file_contents($groupbuildingscurl,'GET', '', 100000);
		if($groupbuildingsscript!=false && strpos($groupbuildingsscript, 'cron')>0) {
			$groupbuildingslines = explode("\n", $groupbuildingsscript);
			$successstring = '{success}';
			$failurestring = '{failure}';
			$createdstring = '{created}';
			$attachedstring = '{attached}';
			foreach($groupbuildingslines as $line) {
				if(strpos($line, $successstring)!==false) {
					$success = true; // found a successful line!
					site_error_log('Group building script ran successfully.');
				} else if(strpos($line, $createdstring)!==false) {
					list($throwaway, $created) = explode($createdstring, $line);
					list($created, $throwaway) = explode('<br />', $created);
					site_error_log('New buildings created: '.$created.'.');
				} else if(strpos($line, $attachedstring)!==false) {
					list($throwaway, $attached) = explode($attachedstring, $line);
					list($attached, $throwaway) = explode('<br />', $attached);
					site_error_log('Homes linked to building: ' . $attached . '.');
				} else if(strpos($line, $failurestring)!==false) {
					list($throwaway, $failure) = explode($failurestring, $line);
					list($failure, $throwaway) = explode('<br />', $failure);
					site_error_log('Group buildings failed: ' . $failure . '.');
				}
			}
		}

		if($success===false) {
			site_error_log('Group building connection failed, raw return: '. $groupbuildingsscript);
		}

		return $success;
	}

	function run_openhouse() {
		global $settings;
		global $handle; // pass through global var for $handle so that get_server_ip() and other sub-functions can log!
		global $mysqli;
		global $now;
		$success = false; // set to false to start.

		$openhouseurl = $this->url.'admin/cron/cron_openhouse.php?hash=58300774';
		list($openhousescript_raw, $http_code) = curl_get_file_contents($openhouseurl,'GET', '', 100000);
		$openhousescript = api_export_decrypt($openhousescript_raw);
		if($openhousescript!=false && strpos($openhousescript, 'success')>0) {
			$openhouselines = explode("\n", $openhousescript);
			$successstring = '{success}';
			foreach($openhouselines as $line) {
				if(strpos($line, $successstring)!==false) {
					$success = true; // found a successful line!
					list($throwaway, $openhousecount) = explode($successstring, $line);
					site_error_log($openhousecount.' open houses removed.');
				}
			}

		}
		if($success===false) {
			// what happened?
			site_error_log('Open house clean failed: '. $openhousescript_raw);
		}
		return $success;
	}

	function get_latest_mlssync_time() {
		$query['site'] = $this->id; // by default, limit to this site.
		$query['success'] = 1;
		$result = get_mlssync_log($query, 'create_date', 'DESC', 1, 1);
		if(is_array($result)==true) {
			foreach($result as $key=>$array) {
				$return = $array['create_date'];
			}
		} else {
			$return = 0;
		}

		$this->mlssync_last_create_date = $return;

		return $this->mlssync_last_create_date;
	}

	function get_mlssync_log($query, $order, $asc) {
		$query['site'] = $this->id; // by default, limit to this site.

		return get_mlssync_log($query);
	}

	function generate_remote_login_url() {
		global $now;
		if($this->cpanel!='' && $this->cpanel!==false) {
			$hash = urlencode(generate_remote_login_hash($this->cpanel));
			$adminurl = $this->url.'admin/login.php?remotelogin='.$hash.'&api='.md5(rand(0,999).$this->url.$now);
			return $adminurl;
		} else {
			return false;
		}

	}

	function register_google_analytics_session_token($tokenvalue) {
		global $mysqli;
		if($this->id!='' && $this->id>0) {
			if(is_array($tokenvalue)) {
				$tokenvalue = json_encode($tokenvalue); // it's an array, so encode it.
			} else if($tokenvalue=='' || $tokenvalue==false) {
				$tokenvalue = '';
			}
			$updatesql = "UPDATE `sites` SET `google_analytics_session_token`='" . $tokenvalue . "' WHERE `id`='" . $this->id . "'";

			$updateresult = $mysqli->query($updatesql);

			if($updateresult===false) {
				$return = $updatesql." returned MySQL error: ".$mysqli->error; // error string can be returned raw for display & troubleeshooting.
			} else {
				$return = true;
				$this->google_analytics_session_token = $tokenvalue;
			}
		} else {
			echo "invalid site for token entry?<br />".PHP_EOL;
			$return = false;
		}
		return $return;
	}

	function google_analytics_session_keepalive() {
		$client = new Google_Client();
		$client->setAuthConfig($_SERVER['DOCUMENT_ROOT'].'/api/includes/google_api_oauth.json');
		$client->setRedirectUri('https://' . $_SERVER['HTTP_HOST'] . '/api/oauth2callback.php');
		$client->addScope(Google_Service_Analytics::ANALYTICS_READONLY);
		$client->setAccessType('offline');
		$client->setAccessToken($this->google_analytics_session_token);
		$refreshresult = $client->getRefreshToken();
		$client->refreshToken($refreshresult);
		if(is_array($this->google_analytics_session_token)) {
			$new_access_token = $client->getAccessToken(); // renew with new create date?
//			echo "Update old to Fresh access token:<br />".PHP_EOL;
//			print_r($new_access_token);
			if(is_array($new_access_token)) {
				$this->register_google_analytics_session_token($new_access_token);
				$client->setAccessToken($this->google_analytics_session_token);
			} else {
				echo "Error:<br />".PHP_EOL;
				var_dump($new_access_token);
			}
		}

		return $client;
	}
	
	# phpfreak code #############
	
	
    function triggerImport() {
		
		$remoteUrl = $this->url.'admin/api/api_update_analytics.php?hash=58300774';
		
		$data = curl_get_file_contents($remoteUrl,'GET', '', 100000);
		
		return true;
		
    }



   
   function gASummary() {
	    // metrics
		
		global $mysqli;
		
		$view_id = $this->google_analytics_profile_id;
		
		$refreshtoken = ($this->google_analytics_session_token) ? $this->google_analytics_session_token : '';
		
		
		if($view_id && $refreshtoken){
			
			$date_from = date('Y-m-d', time()-6*24*60*60); // 7 days ago
			$date_to = date('Y-m-d', time()); // 1 days ago
			$client = new Google_Client();
			$client->setAuthConfig($_SERVER['DOCUMENT_ROOT'].'/api/includes/google_api_oauth.json');
			$client->setRedirectUri('https://' . $_SERVER['HTTP_HOST'] . '/api/oauth2callback.php');
			$client->addScope(Google_Service_Analytics::ANALYTICS_READONLY);
			$client->setAccessType('offline');
			$client->setAccessToken($refreshtoken);
		
			$client->refreshToken($refreshtoken);
			$site_listings = file_get_contents($this->url .'/admin/api/apiListings.php?hash=58300774');
			$site_listing_urls = json_decode(api_export_decrypt($site_listings),true);
			$_params1[] = 'date';
			$_params1[] = 'date_day';
			$_params1[] = 'page_url';
			$_params1[] = 'pageviews'; 
			$search_params['organic'] = 'organic'; 			
			$search_params['social'] = 'social'; 			
			$search_params['referral'] = 'referral'; 			
			$search_params['email'] = 'email';			
			if($site_listing_urls){
				
				foreach($site_listing_urls as &$url){
					
					$analytics = new Google_Service_Analytics($client);
				//	echo '<pre>' ; print_r($url['listing_url']); die;
					$optParams = [
							'dimensions' => 'ga:date,ga:day,ga:pagePath',
							'sort'=>'ga:date',
							'filters'=>"ga:pagePath==".$url['listing_url']
							] ;
					try{
						$results = $analytics->data_ga->get(
							   'ga:'.$view_id,
							   '7daysAgo',
							   'today',
							   'ga:pageviews',
							   $optParams
						);
						
					}catch(Exception $e){
						
						echo $e->getMessage().'<br>';
						continue;
						
					} 
					//echo '<pre>' ; print_r($results) ; die;
					if($results->totalResults > 0){
						
						$retData = array();
						
						foreach($results['rows'] as $row)
							{
							   $dataRow = array();
							   foreach($_params1 as $colNr => $column)
							   {
								  $dataRow[$column] = $row[$colNr];
							   }
							   
							   $retData[] = $dataRow;
							 
							}
						
						$url['data'] =  $retData;	 
						
					}
					
					
					try{
						$optParams = [
						'dimensions'=>'ga:medium',
						'filters'=>"ga:pagePath==".$url['listing_url']
						] ;
						$resultsSource = $analytics->data_ga->get(
							   'ga:'.$view_id,
							   '7daysAgo',
							   'today',
							   'ga:pageviews',
							   $optParams
						);
						
					}catch(Exception $e){
						
						echo $e->getMessage().'<br>';
						continue;
					}
					
					if($resultsSource->totalResults > 0){
						$dataRow = array();
						foreach($resultsSource['rows'] as $row)
							{
								 if(isset($search_params[$row[0]])){
									 //echo $row[0].'<br>';
									$dataRow[$row[0]] = $row[1]; 
								  }else{
									 // echo $row[0].'<br>';
									$dataRow['other'] = $row[1];   
								  }
							
							}
							//echo '<pre>' ; print_r($dataRow);
						$url['dataSource'] =  $dataRow;	 
						
					}
				}
				$jsonData = json_encode($site_listing_urls); 
				$fileName = md5($this->url.date('Ymd')).'.txt';
				$filePath = '../api/cache/'.$fileName;
				file_put_contents($filePath,$jsonData);
			}
			
		}else{
			echo 'Analytic View id not found \n';
			
		}	
	}
	# phpfreak code end #############
	function update_google_analytics_account_id($accountid, $profileid) {
		global $mysqli;
		if($this->id!='' && $this->id>0) {
			// $accountid has to be number only.
			if(is_numeric(trim($accountid)) || $accountid=='' || $accountid==false) {
				if($accountid==false) {
					$accountid = '';
				}
				$updatesql = "UPDATE `sites` SET `google_analytics_id`='" . $accountid . "', `google_analytics_profile_id`='".$profileid."' WHERE `id`='" . $this->id . "'";

				$updateresult = $mysqli->query($updatesql);

				if($updateresult===false) {
					$return = $updatesql." returned MySQL error: ".$mysqli->error; // error string can be returned raw for display & troubleeshooting.
				} else {
					$return = true;
					$this->google_analytics_id = $accountid;
					$this->google_analytics_profile_id = $profileid;
				}
			} else {
				$return = false;
			}

		} else {
			echo "invalid site for analytics ID entry?<br />".PHP_EOL;
			$return = false;
		}
		
		return $return;
	}

	function pdf_file_link($num=0, $anchor='', $prefix='', $suffix='', $cssclass = '') {
		// universal PDF link displayer that can be used on both floorplans & feature sheet PDFs
		global $settings;
		return get_pdf_file_link('homes', $this, $num, $anchor, $prefix, $suffix, $cssclass);
	}
}



class server {
	var $id;
	var $ip;
	var $apikey;

	var $name; // just short name from IP. 104.247.75.224 becomes 104.
	var $description;
	var $provider; // just string.
	// TODO:  add other things like WHM API key etc., derived from MySQL, to make hooking into servers easy.

	function initialize($id, $serverarray = false) {
		global $mysql_time;
		global $settings;
		global $local;
		global $mysqli;

		$data = false;

		if($serverarray===false) {
			// default way of querying based on input of home ID.
			// the get_properties() function is designed to get enough information to show the list of listings (listing gallery) for display, so even though it may be tempting to replace the below query with another iteration of get_properties('', $query['id'],...);, DONT DO IT. - JEff 2017-09-20
			$singlquery = array('id'=>$id);
			$rows = get_servers($singlquery, '', '', true);
			if($rows) {
				$data = $rows[$id]; // first and only row based on ID result.
			} else {
				$data = false;
			}
		} else if(is_array($serverarray)) {
			// retrieve from the $data array - the get_properties function already returned a lot of the fields to speed it up, this reduces query time for map searches.
			$data = $serverarray;// feed $homearray into $data then start processing the data only, bypass the MySQL query.
		}

		if($data!==false) {
			foreach($data as $key=>$value) {
				$this->data[$key] = $value;
			}

			$this->id = $data['id'];
			$this->ip = trim($data['ip']);
			$this->apikey = trim($data['apikey']);

			if($data['name']=='') {
				list($this->name, $throwaway) = explode('.', $this->ip); // get the first block of IP as short name.
			} else {
				$this->name = $data['name'];
			}

			$this->provider = $data['provider'];
			$this->description = $data['description'];

			return $this->id;
		} else {
			return false;
		}
	}

	function get_sites($query, $data = true) {
		// effectively just run get_sites() function with added query vars if needed.
		if(is_array($query)) {
			$query['server'] = $this->id;
		} else {
			$query = array('server'=>$this->id); // singular query passed.
		}
		return get_sites($query, 'name', 'ASC', 10000, 1, false, true);
	}

	function get_sites_count() {
		// effectively just run get_sites() function with added query vars if needed.
		$query['server'] = $this->id;
		return get_sites($query, 'name', 'ASC', 10000, 1, true);
	}
}





class rets {
	var $id;
	var $url;
	var $domain;

	var $name; // just short name from IP. 104.247.75.224 becomes 104.

	function initialize($id, $retsarray = false) {
		global $mysql_time;
		global $settings;
		global $local;
		global $mysqli;

		$data = false;

		if($retsarray===false) {
			// default way of querying based on input of home ID.
			// the get_properties() function is designed to get enough information to show the list of listings (listing gallery) for display, so even though it may be tempting to replace the below query with another iteration of get_properties('', $query['id'],...);, DONT DO IT. - JEff 2017-09-20
			$singlquery = array('id'=>$id);
			$rows = get_rets($singlquery, '', '', true);
			if($rows) {
				$data = $rows[$id]; // first and only row based on ID result.
			} else {
				$data = false;
			}
		} else if(is_array($retsarray)) {
			// retrieve from the $data array - the get_properties function already returned a lot of the fields to speed it up, this reduces query time for map searches.
			$data = $retsarray;// feed $homearray into $data then start processing the data only, bypass the MySQL query.
		}

		if($data!==false) {
			foreach($data as $key=>$value) {
				$this->data[$key] = $value;
			}

			$this->id = $data['id'];
			$this->url = $data['url'];
			$this->name = $data['name'];

			$this->domain = get_host($this->url);

			return $this->id;
		} else {
			return false;
		}
	}

	function get_sites($query, $data = true) {
		// effectively just run get_sites() function with added query vars if needed.
		if(is_array($query)) {
			$query['server'] = $this->id;
		} else {
			$query = array('server'=>$this->id); // singular query passed.
		}
		return get_sites($query, 'name', 'ASC', 10000, 1, false, true);
	}

	function get_sites_count() {
		// effectively just run get_sites() function with added query vars if needed.
		$query['server'] = $this->id;
		return get_sites($query, 'name', 'ASC', 10000, 1, true);
	}

}

?>