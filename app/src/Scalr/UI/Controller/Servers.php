<?php

use Scalr\Acl\Acl;
use Scalr\Server\Alerts;
use Scalr\Service\Aws\Ec2\DataType\InstanceAttributeType;
use Scalr\Modules\PlatformFactory;
use Scalr\Model\Entity;
use Scalr\UI\Request\JsonData;
use Scalr\Modules\Platforms\Openstack\Helpers\OpenstackHelper;
use Scalr\Exception\Http\NotFoundException;

class Scalr_UI_Controller_Servers extends Scalr_UI_Controller
{
    const CALL_PARAM_NAME = 'serverId';

    public function defaultAction()
    {
        $this->viewAction();
    }

    public function getList(array $status = array())
    {
        $retval = array();

        $sql = "SELECT * FROM servers WHERE env_id = ".$this->db->qstr($this->getEnvironmentId());
        if ($this->getParam('farmId'))
            $sql .= " AND farm_id = ".$this->db->qstr($this->getParam('farmId'));

        if ($this->getParam('farmRoleId'))
            $sql .= " AND farm_roleid = ".$this->db->qstr($this->getParam('farmRoleId'));

        if (!empty($status))
            $sql .= "AND status IN ('".implode("','", $status)."')";

        $s = $this->db->execute($sql);
        while ($server = $s->fetchRow()) {
            $retval[$server['server_id']] = $server;
        }

        return $retval;
    }

    public function downloadScalarizrDebugLogAction()
    {
        $dbServer = DBServer::LoadByID($this->getParam('serverId'));
        $this->user->getPermissions()->validate($dbServer);

        $fileName = "scalarizr_debug-{$dbServer->serverId}.log";
        $retval = base64_decode($dbServer->scalarizr->system->getDebugLog());

        $this->response->setHeader('Pragma', 'private');
        $this->response->setHeader('Cache-control', 'private, must-revalidate');
        $this->response->setHeader('Content-type', 'plain/text');
        $this->response->setHeader('Content-Disposition', 'attachment; filename="'.$fileName.'"');
        $this->response->setHeader('Content-Length', strlen($retval));

        $this->response->setResponse($retval);
    }

    public function xLockAction()
    {
        $this->request->restrictAccess(Acl::RESOURCE_FARMS_SERVERS);

        $this->request->defineParams(array(
            'serverId'
        ));

        $dbServer = DBServer::LoadByID($this->getParam('serverId'));
        $this->user->getPermissions()->validate($dbServer);

        if ($dbServer->platform != SERVER_PLATFORMS::EC2)
            throw new Exception("Server lock supported ONLY by EC2");

        $env = Scalr_Environment::init()->loadById($dbServer->envId);
        $ec2 = $env->aws($dbServer->GetCloudLocation())->ec2;

        $newValue = !$ec2->instance->describeAttribute($dbServer->GetCloudServerID(), InstanceAttributeType::disableApiTermination());

        $ec2->instance->modifyAttribute(
            $dbServer->GetCloudServerID(),
            InstanceAttributeType::disableApiTermination(),
            $newValue
        );

        $dbServer->SetProperty(EC2_SERVER_PROPERTIES::IS_LOCKED, $newValue);

        $this->response->success();
    }

    public function xTroubleshootAction()
    {
        $this->request->restrictAccess(Acl::RESOURCE_FARMS_SERVERS);

        $this->request->defineParams(array(
            'serverId'
        ));

        $dbServer = DBServer::LoadByID($this->getParam('serverId'));
        $this->user->getPermissions()->validate($dbServer);

        $dbServer->status = SERVER_STATUS::TROUBLESHOOTING;
        $dbServer->Save();

        // Send before host terminate to the server to detach all used volumes.
        $msg = new Scalr_Messaging_Msg_BeforeHostTerminate($dbServer);

        if ($dbServer->farmRoleId != 0) {
            foreach (Scalr_Role_Behavior::getListForFarmRole($dbServer->GetFarmRoleObject()) as $behavior) {
                $msg = $behavior->extendMessage($msg, $dbServer);
            }
        }
        $dbServer->SendMessage($msg);

        Scalr::FireEvent($dbServer->farmId, new HostDownEvent($dbServer));

        $this->response->success();
    }

    public function xGetWindowsPasswordAction()
    {
        $this->request->restrictAccess(Acl::RESOURCE_SECURITY_RETRIEVE_WINDOWS_PASSWORDS);

        $this->request->defineParams(array(
            'serverId'
        ));

        $dbServer = DBServer::LoadByID($this->getParam('serverId'));
        $this->user->getPermissions()->validate($dbServer);

        if ($dbServer->platform == SERVER_PLATFORMS::EC2) {
            $env = Scalr_Environment::init()->loadById($dbServer->envId);
            $ec2 = $env->aws($dbServer->GetCloudLocation())->ec2;

            $encPassword = $ec2->instance->getPasswordData($dbServer->GetCloudServerID());
            $encPassword = str_replace('\/', '/', trim($encPassword->passwordData));
        } elseif (PlatformFactory::isOpenstack($dbServer->platform)) {
            if (in_array($dbServer->platform, array(SERVER_PLATFORMS::RACKSPACENG_UK, SERVER_PLATFORMS::RACKSPACENG_US))) {
                $password = $dbServer->GetProperty(OPENSTACK_SERVER_PROPERTIES::ADMIN_PASS);
            } else {
                $env = Scalr_Environment::init()->loadById($dbServer->envId);
                $os = $env->openstack($dbServer->platform, $dbServer->GetCloudLocation());

                //TODO: Check is extension supported
                $encPassword = trim($os->servers->getEncryptedAdminPassword($dbServer->GetCloudServerID()));
            }
        } else
            throw new Exception("Requested operation supported only by EC2");

        if ($encPassword) {
            $privateKey = Scalr_SshKey::init()->loadGlobalByFarmId($dbServer->envId, $dbServer->farmId, $dbServer->GetCloudLocation(), $dbServer->platform);
            $password = Scalr_Util_CryptoTool::opensslDecrypt(base64_decode($encPassword), $privateKey->getPrivate());
        }

        $this->response->data(array('password' => $password, 'encodedPassword' => $encPassword));
    }

    public function xGetStorageDetailsAction()
    {
        $this->request->restrictAccess(Acl::RESOURCE_FARMS_SERVERS);

        $dbServer = DBServer::LoadByID($this->getParam('serverId'));
        $this->user->getPermissions()->validate($dbServer);

        if ($dbServer->IsSupported('2.5.4')) {
            $info = $dbServer->scalarizr->system->mounts();
        } else {
            if ($dbServer->GetFarmRoleObject()->GetRoleObject()->osFamily == 'windows') {
                $storages = array('C' => array());
            } else {
                $storages = array('/' => array());
                $storageConfigs = $dbServer->GetFarmRoleObject()->getStorage()->getVolumes($dbServer->index);
                foreach ($storageConfigs as $config) {
                    $config = $config[$dbServer->index];

                    $storages[$config->config->mpoint] = array();
                }
            }

            $info = $dbServer->scalarizr->system->statvfs(array_keys($storages));
        }

        $this->response->data(array('data' => $info));
    }

    public function xGetHealthDetailsAction()
    {
        $this->request->restrictAccess(Acl::RESOURCE_FARMS_SERVERS);

        $dbServer = DBServer::LoadByID($this->getParam('serverId'));
        $this->user->getPermissions()->validate($dbServer);


        $data = array();

        try {
            $la = $dbServer->scalarizr->system->loadAverage();
            $data['la'] = number_format($la[0], 2);
        } catch (Exception $e) {}

        try {
            $mem = $dbServer->scalarizr->system->memInfo();
            $data['memory'] = array('total' => round($mem->total_real / 1024 / 1024, 1), 'free' => round(($mem->total_free+$mem->cached) / 1024 / 1024, 1));
        } catch (Exception $e) {}

        try {
            if ($dbServer->osType == 'windows') {
                $cpu = $dbServer->scalarizr->system->cpuStat();
            } else {
                $cpu1 = $dbServer->scalarizr->system->cpuStat();
                sleep(1);
                $cpu2 = $dbServer->scalarizr->system->cpuStat();

                $dif['user'] = $cpu2->user - $cpu1->user;
                $dif['nice'] = $cpu2->nice - $cpu1->nice;
                $dif['sys'] =  $cpu2->system - $cpu1->system;
                $dif['idle'] = $cpu2->idle - $cpu1->idle;
                $total = array_sum($dif);
                foreach($dif as $x=>$y)
                    $cpu[$x] = $total != 0 ? round($y / $total * 100, 1) : 0;
            }

            $data['cpu'] = $cpu;
        } catch (Exception $e) {}

        $this->response->data(array('data' => $data));
    }

    public function xResendMessageAction()
    {
        $this->request->restrictAccess(Acl::RESOURCE_FARMS_SERVERS);

        $message = $this->db->GetRow("SELECT * FROM messages WHERE server_id=? AND messageid=? LIMIT 1",array(
            $this->getParam('serverId'), $this->getParam('messageId')
        ));

        if ($message) {
            if ($message['message_format'] == 'json') {
                $serializer = new Scalr_Messaging_JsonSerializer();
            } else {
                $serializer = new Scalr_Messaging_XmlSerializer();
            }

            $msg = $serializer->unserialize($message['message']);

            $dbServer = DBServer::LoadByID($this->getParam('serverId'));
            $this->user->getPermissions()->validate($dbServer);

            if (in_array($dbServer->status, array(SERVER_STATUS::RUNNING, SERVER_STATUS::INIT))) {
                $this->db->Execute("UPDATE messages SET status=?, handle_attempts='0' WHERE id=?", array(MESSAGE_STATUS::PENDING, $message['id']));
                $dbServer->SendMessage($msg);
            }
            else
                throw new Exception("Scalr unable to re-send message. Server should be in running state.");

            $this->response->success('Message successfully re-sent to the server');
        } else {
            throw new Exception("Message not found");
        }
    }

    public function xListMessagesAction()
    {
        if (!$this->request->isAllowed(Acl::RESOURCE_FARMS_SERVERS) && !$this->request->isAllowed(Acl::RESOURCE_FARMS_ROLES, Acl::PERM_FARMS_ROLES_CREATE)) {
            throw new Scalr_Exception_InsufficientPermissions();
        }

        $this->request->defineParams(array(
            'serverId',
            'sort' => array('type' => 'string', 'default' => 'id'),
            'dir' => array('type' => 'string', 'default' => 'DESC')
        ));

        $dbServer = DBServer::LoadByID($this->getParam('serverId'));
        $this->user->getPermissions()->validate($dbServer);

        $sql = "SELECT *, message_name as message_type FROM messages WHERE server_id='{$dbServer->serverId}'";
        $response = $this->buildResponseFromSql($sql, array("server_id", "message", "messageid"));

        foreach ($response["data"] as &$row) {

            if (!$row['message_type']) {
                preg_match("/^<\?xml [^>]+>[^<]*<message(.*?)name=\"([A-Za-z0-9_]+)\"/si", $row['message'], $matches);
                $row['message_type'] = $matches[2];
            }

            $row['message'] = '';
            $row['dtlasthandleattempt'] = Scalr_Util_DateTime::convertTz($row['dtlasthandleattempt']);
            if ($row['handle_attempts'] == 0 && $row['status'] == 1)
                $row['handle_attempts'] = 1;
        }

        $this->response->data($response);
    }

    public function messagesAction()
    {
        if (!$this->request->isAllowed(Acl::RESOURCE_FARMS_SERVERS) && !$this->request->isAllowed(Acl::RESOURCE_FARMS_ROLES, Acl::PERM_FARMS_ROLES_CREATE)) {
            throw new Scalr_Exception_InsufficientPermissions();
        }
        $this->response->page('ui/servers/messages.js', array('serverId' => $this->getParam('serverId')));
    }

    public function viewAction()
    {
        if (!$this->request->isAllowed(Acl::RESOURCE_FARMS_SERVERS) && !$this->request->isAllowed(Acl::RESOURCE_FARMS_ROLES, Acl::PERM_FARMS_ROLES_CREATE)) {
            throw new Scalr_Exception_InsufficientPermissions();
        }
        $this->response->page('ui/servers/view.js', array(
            'mindtermEnabled' => \Scalr::config('scalr.ui.mindterm_enabled')
        ), array('ui/servers/actionsmenu.js'), array('ui/servers/view.css'));
    }

    public function sshConsoleAction()
    {
        $this->request->restrictAccess(Acl::RESOURCE_FARMS_SERVERS, Acl::PERM_FARMS_SERVERS_SSH_CONSOLE);

        $dbServer = DBServer::LoadByID($this->getParam('serverId'));
        $this->user->getPermissions()->validate($dbServer);
        $ipAddress = $dbServer->getSzrHost();

        if ($ipAddress) {
            $dBFarm = $dbServer->GetFarmObject();
            $dbRole = $dbServer->GetFarmRoleObject()->GetRoleObject();

            $sshPort = $dbRole->getProperty(DBRole::PROPERTY_SSH_PORT);
            if (!$sshPort)
                $sshPort = 22;

            $cSshPort = $dbServer->GetProperty(SERVER_PROPERTIES::CUSTOM_SSH_PORT);
            if ($cSshPort)
                $sshPort = $cSshPort;

            $userSshSettings = $this->user->getSshConsoleSettings(false, true, $dbServer->serverId);

            $sshSettings = array(
                'serverId' => $dbServer->serverId,
                'serverIndex' => $dbServer->index,
                'remoteIp' => $ipAddress,
                'localIp' => $dbServer->localIp,
                'farmName' => $dBFarm->Name,
                'farmId' => $dbServer->farmId,
                'roleName' => $dbRole->name,
                Scalr_Account_User::VAR_SSH_CONSOLE_PORT => $userSshSettings[Scalr_Account_User::VAR_SSH_CONSOLE_PORT] ? $userSshSettings[Scalr_Account_User::VAR_SSH_CONSOLE_PORT] : $sshPort,
                Scalr_Account_User::VAR_SSH_CONSOLE_USERNAME => $userSshSettings[Scalr_Account_User::VAR_SSH_CONSOLE_USERNAME] ? $userSshSettings[Scalr_Account_User::VAR_SSH_CONSOLE_USERNAME] : ($dbServer->platform == SERVER_PLATFORMS::GCE ? 'scalr' : 'root'),
                Scalr_Account_User::VAR_SSH_CONSOLE_LOG_LEVEL => $userSshSettings[Scalr_Account_User::VAR_SSH_CONSOLE_LOG_LEVEL] ? $userSshSettings[Scalr_Account_User::VAR_SSH_CONSOLE_LOG_LEVEL] : 'CONFIG',
                Scalr_Account_User::VAR_SSH_CONSOLE_PREFERRED_PROVIDER => $userSshSettings[Scalr_Account_User::VAR_SSH_CONSOLE_PREFERRED_PROVIDER] ? $userSshSettings[Scalr_Account_User::VAR_SSH_CONSOLE_PREFERRED_PROVIDER] : '',
                Scalr_Account_User::VAR_SSH_CONSOLE_ENABLE_AGENT_FORWARDING => $userSshSettings[Scalr_Account_User::VAR_SSH_CONSOLE_ENABLE_AGENT_FORWARDING] ? $userSshSettings[Scalr_Account_User::VAR_SSH_CONSOLE_ENABLE_AGENT_FORWARDING] : '0',
            );

            if ($this->request->isAllowed(Acl::RESOURCE_SECURITY_SSH_KEYS)) {
                $sshKey = Scalr_SshKey::init()->loadGlobalByFarmId(
                    $dbServer->envId,
                    $dbServer->farmId,
                    $dbServer->GetFarmRoleObject()->CloudLocation,
                    $dbServer->platform
                );

                if (!$sshKey) {
                    throw new NotFoundException(sprintf(
                        "Cannot find ssh key corresponding to environment:'%d', farm:'%d', platform:'%s', cloud location:'%s'.",
                        $dbServer->envId,
                        $dbServer->farmId,
                        strip_tags($dbServer->platform),
                        strip_tags($dbServer->GetFarmRoleObject()->CloudLocation)
                    ));
                }

                $cloudKeyName = $sshKey->cloudKeyName;
                if (substr_count($cloudKeyName, '-') == 2) {
                    $cloudKeyName = str_replace('-'.SCALR_ID, '-'.$sshKey->cloudLocation.'-'.SCALR_ID, $cloudKeyName);
                }

                $sshSettings['ssh.console.key'] = base64_encode($sshKey->getPrivate());
                $sshSettings['ssh.console.putty_key'] = base64_encode($sshKey->getPuttyPrivateKey());
                $sshSettings[Scalr_Account_User::VAR_SSH_CONSOLE_KEY_NAME] = $userSshSettings[Scalr_Account_User::VAR_SSH_CONSOLE_KEY_NAME] ? $userSshSettings[Scalr_Account_User::VAR_SSH_CONSOLE_KEY_NAME] : $cloudKeyName;
                $sshSettings[Scalr_Account_User::VAR_SSH_CONSOLE_DISABLE_KEY_AUTH] = $userSshSettings[Scalr_Account_User::VAR_SSH_CONSOLE_DISABLE_KEY_AUTH] ? $userSshSettings[Scalr_Account_User::VAR_SSH_CONSOLE_DISABLE_KEY_AUTH] : '0';
            } else {
                $sshSettings['ssh.console.key'] = '';
                $sshSettings['ssh.console.putty_key'] = '';
                $sshSettings[Scalr_Account_User::VAR_SSH_CONSOLE_KEY_NAME] = '';
                $sshSettings[Scalr_Account_User::VAR_SSH_CONSOLE_DISABLE_KEY_AUTH] = '1';
            }

            $this->response->page('ui/servers/sshconsole.js', $sshSettings);
        }
        else
            throw new Exception(_("SSH console not available for this server or server is not yet initialized"));
    }

    public function xServerCancelOperationAction()
    {
        if (!$this->request->isAllowed(Acl::RESOURCE_FARMS_SERVERS) && !$this->request->isAllowed(Acl::RESOURCE_FARMS_ROLES, Acl::PERM_FARMS_ROLES_CREATE)) {
            throw new Scalr_Exception_InsufficientPermissions();
        }

        $this->request->defineParams(array(
            'serverId'
        ));

        $dbServer = DBServer::LoadByID($this->getParam('serverId'));
        $this->user->getPermissions()->validate($dbServer);

        $bt_id = $this->db->GetOne("
            SELECT id FROM bundle_tasks WHERE server_id=? AND prototype_role_id='0' AND status NOT IN (?,?,?) LIMIT 1
        ", array(
            $dbServer->serverId,
            SERVER_SNAPSHOT_CREATION_STATUS::FAILED,
            SERVER_SNAPSHOT_CREATION_STATUS::SUCCESS,
            SERVER_SNAPSHOT_CREATION_STATUS::CANCELLED
        ));

        if ($bt_id) {
            $BundleTask = BundleTask::LoadById($bt_id);
            $BundleTask->SnapshotCreationFailed("Server was terminated before snapshot was created.");
        }

        if ($dbServer->status == SERVER_STATUS::IMPORTING) {
            $dbServer->Remove();
        } else {
            $dbServer->terminate(DBServer::TERMINATE_REASON_SNAPSHOT_CANCELLATION, true, $this->user);
            if (PlatformFactory::isOpenstack($dbServer->platform)) {
                OpenstackHelper::removeIpFromServer($dbServer);
            }
        }

        $this->response->success("Server was successfully canceled and removed from database");
    }

    public function xUpdateUpdateClientAction()
    {
        $this->request->restrictAccess(Acl::RESOURCE_FARMS_SERVERS);

        $this->request->defineParams(array(
            'serverId'
        ));

        /* @var Entity\Script $scr */
        $scr = Entity\Script::find(array(
            array('id' => 3803),
            array('accountId' => NULL)
        ));

        if (! $scr)
            throw new Exception("Automatical scalarizr update doesn't supported by this scalr version");

        $dbServer = DBServer::LoadByID($this->getParam('serverId'));
        $this->user->getPermissions()->validate($dbServer);

        $scriptSettings = array(
            'version' => $scr->getLatestVersion()->version,
            'scriptid' => 3803,
            'timeout' => 300,
            'issync' => 0,
            'params' => serialize(array()),
            'type' => Scalr_Scripting_Manager::ORCHESTRATION_SCRIPT_TYPE_SCALR
        );

        $message = new Scalr_Messaging_Msg_ExecScript("Manual");
        $message->setServerMetaData($dbServer);

        $script = Scalr_Scripting_Manager::prepareScript($scriptSettings, $dbServer);

        $itm = new stdClass();
        // Script
        $itm->asynchronous = ($script['issync'] == 1) ? '0' : '1';
        $itm->timeout = $script['timeout'];
        if ($script['body']) {
            $itm->name = $script['name'];
            $itm->body = $script['body'];
        } else {
            $itm->path = $script['path'];
        }
        $itm->executionId = $script['execution_id'];

        $message->scripts = array($itm);

        $dbServer->SendMessage($message);

        $this->response->success('Scalarizr update-client update successfully initiated');
    }

    public function xUpdateAgentAction()
    {
        $this->request->restrictAccess(Acl::RESOURCE_FARMS_SERVERS);

        $this->request->defineParams(array(
            'serverId'
        ));

        /* @var Entity\Script $scr */
        $scr = Entity\Script::find(array(
            array('id' => 2102),
            array('accountId' => NULL)
        ));

        if (! $scr)
            throw new Exception("Automatical scalarizr update doesn't supported by this scalr version");

        $dbServer = DBServer::LoadByID($this->getParam('serverId'));
        $this->user->getPermissions()->validate($dbServer);

        $scriptSettings = array(
            'version' => $scr->getLatestVersion()->version,
            'scriptid' => 2102,
            'timeout' => 300,
            'issync' => 0,
            'params' => serialize(array()),
            'type' => Scalr_Scripting_Manager::ORCHESTRATION_SCRIPT_TYPE_SCALR
        );

        $message = new Scalr_Messaging_Msg_ExecScript("Manual");
        $message->setServerMetaData($dbServer);

        $script = Scalr_Scripting_Manager::prepareScript($scriptSettings, $dbServer);

        $itm = new stdClass();
        // Script
        $itm->asynchronous = ($script['issync'] == 1) ? '0' : '1';
        $itm->timeout = $script['timeout'];
        if ($script['body']) {
            $itm->name = $script['name'];
            $itm->body = $script['body'];
        } else {
            $itm->path = $script['path'];
        }
        $itm->executionId = $script['execution_id'];

        $message->scripts = array($itm);

        $dbServer->SendMessage($message);

        $this->response->success('Scalarizr update successfully initiated. Please wait a few minutes and then refresh the page');
    }

    public function xListServersAction()
    {
        if (!$this->request->isAllowed(Acl::RESOURCE_FARMS_SERVERS) && !$this->request->isAllowed(Acl::RESOURCE_FARMS_ROLES, Acl::PERM_FARMS_ROLES_CREATE)) {
            throw new Scalr_Exception_InsufficientPermissions();
        }

        $this->request->defineParams(array(
            'roleId' => array('type' => 'int'),
            'farmId' => array('type' => 'int'),
            'farmRoleId' => array('type' => 'int'),
            'serverId',
            'hideTerminated' => array('type' => 'bool'),
            'sort' => array('type' => 'json')
        ));

        $sql = 'SELECT servers.*, farms.name AS farm_name, roles.name AS role_name, farm_roles.alias AS role_alias, ste.last_error AS termination_error
                FROM servers
                LEFT JOIN farms ON servers.farm_id = farms.id
                LEFT JOIN farm_roles ON farm_roles.id = servers.farm_roleid
                LEFT JOIN roles ON roles.id = farm_roles.role_id
                LEFT JOIN server_termination_errors ste ON servers.server_id = ste.server_id
                WHERE servers.env_id = ? AND :FILTER:';
        $args = array($this->getEnvironmentId());

        if ($this->getParam('cloudServerId')) {
            $sql = str_replace('WHERE', 'LEFT JOIN server_properties ON servers.server_id = server_properties.server_id WHERE', $sql);
            $sql .= ' AND (';

            $sql .= 'server_properties.name = ? AND server_properties.value = ?';
            $args[] = CLOUDSTACK_SERVER_PROPERTIES::SERVER_ID;
            $args[] = $this->getParam('cloudServerId');

            $sql .= ' OR server_properties.name = ? AND server_properties.value = ?';
            $args[] = EC2_SERVER_PROPERTIES::INSTANCE_ID;
            $args[] = $this->getParam('cloudServerId');

            $sql .= ' OR server_properties.name = ? AND server_properties.value = ?';
            $args[] = EUCA_SERVER_PROPERTIES::INSTANCE_ID;
            $args[] = $this->getParam('cloudServerId');

            $sql .= ' OR server_properties.name = ? AND server_properties.value = ?';
            $args[] = GCE_SERVER_PROPERTIES::SERVER_NAME;
            $args[] = $this->getParam('cloudServerId');

            $sql .= ' OR server_properties.name = ? AND server_properties.value = ?';
            $args[] = OPENSTACK_SERVER_PROPERTIES::SERVER_ID;
            $args[] = $this->getParam('cloudServerId');

            $sql .= ' OR server_properties.name = ? AND server_properties.value = ?';
            $args[] = RACKSPACE_SERVER_PROPERTIES::SERVER_ID;
            $args[] = $this->getParam('cloudServerId');

            $sql .= ')';
        }

        if ($this->getParam('cloudServerLocation')) {
            if (!strstr($sql, 'LEFT JOIN server_properties ON servers.server_id = server_properties.server_id'))
                $sql = str_replace('WHERE', 'LEFT JOIN server_properties ON servers.server_id = server_properties.server_id WHERE', $sql);
            $sql .= ' AND (';

            $sql .= 'server_properties.name = ? AND server_properties.value = ?';
            $args[] = CLOUDSTACK_SERVER_PROPERTIES::CLOUD_LOCATION;
            $args[] = $this->getParam('cloudServerLocation');

            $sql .= ' OR server_properties.name = ? AND server_properties.value = ?';
            $args[] = EC2_SERVER_PROPERTIES::REGION;
            $args[] = $this->getParam('cloudServerLocation');

            $sql .= ' OR server_properties.name = ? AND server_properties.value = ?';
            $args[] = EUCA_SERVER_PROPERTIES::REGION;
            $args[] = $this->getParam('cloudServerLocation');

            $sql .= ' OR server_properties.name = ? AND server_properties.value = ?';
            $args[] = GCE_SERVER_PROPERTIES::CLOUD_LOCATION;
            $args[] = $this->getParam('cloudServerLocation');

            $sql .= ' OR server_properties.name = ? AND server_properties.value = ?';
            $args[] = OPENSTACK_SERVER_PROPERTIES::CLOUD_LOCATION;
            $args[] = $this->getParam('cloudServerLocation');

            $sql .= ' OR server_properties.name = ? AND server_properties.value = ?';
            $args[] = RACKSPACE_SERVER_PROPERTIES::DATACENTER;
            $args[] = $this->getParam('cloudServerLocation');

            $sql .= ')';
        }

        if ($this->getParam('hostname')) {
            if (!strstr($sql, 'LEFT JOIN server_properties ON servers.server_id = server_properties.server_id'))
                $sql = str_replace('WHERE', 'LEFT JOIN server_properties ON servers.server_id = server_properties.server_id WHERE', $sql);
            $sql .= ' AND (';

            $sql .= 'server_properties.name = ? AND server_properties.value LIKE ?';
            $args[] = Scalr_Role_Behavior::SERVER_BASE_HOSTNAME;
            $args[] = '%' . $this->getParam('hostname') . '%';

            $sql .= ')';
        }

        if ($this->getParam('farmId')) {
            $sql .= " AND farm_id=?";
            $args[] = $this->getParam('farmId');
        }

        if ($this->request->isAllowed(Acl::RESOURCE_FARMS_SERVERS)) {
            if (!$this->request->isAllowed(Acl::RESOURCE_FARMS, Acl::PERM_FARMS_NOT_OWNED_FARMS)) {
                $sql .= " AND (farms.created_by_id = ? OR servers.status IN (?, ?) AND farms.id IS NULL)";
                $args[] = $this->user->getId();
                $args[] = SERVER_STATUS::IMPORTING;
                $args[] = SERVER_STATUS::TEMPORARY;
            }
        } else {
            //show servers related to role creation process only
            $sql .= ' AND servers.status IN (?, ?)';
            $args[] = SERVER_STATUS::IMPORTING;
            $args[] = SERVER_STATUS::TEMPORARY;
        }

        if ($this->getParam('farmRoleId')) {
            $sql .= " AND farm_roleid=?";
            $args[] = $this->getParam('farmRoleId');
        }

        if ($this->getParam('roleId')) {
            $sql .= " AND farm_roles.role_id=?";
            $args[] = $this->getParam('roleId');
        }

        if ($this->getParam('serverId')) {
            $sql .= " AND servers.server_id=?";
            $args[] = $this->getParam('serverId');
        }

        if ($this->getParam('imageId')) {
            $sql .= " AND image_id=?";
            $args[] = $this->getParam('imageId');
        }

        if ($this->getParam('hideTerminated')) {
            $sql .= ' AND servers.status != ?';
            $args[] = SERVER_STATUS::TERMINATED;
        }

        $response = $this->buildResponseFromSql2($sql, array('platform', 'farm_name', 'role_name', 'role_alias', 'index', 'servers.server_id', 'remote_ip', 'local_ip', 'uptime', 'status'),
            array('servers.server_id', 'farm_id', 'farms.name', 'remote_ip', 'local_ip', 'servers.status', 'farm_roles.alias'), $args);

        foreach ($response["data"] as &$row) {
            try {
                $dbServer = DBServer::LoadByID($row['server_id']);
            } catch (Exception $e) {
                continue;
            }
            
            
            try {
                $row['cloud_server_id'] = $dbServer->GetCloudServerID();
                $row['hostname'] = $dbServer->GetProperty(Scalr_Role_Behavior::SERVER_BASE_HOSTNAME);

                if (in_array($dbServer->status, array(SERVER_STATUS::RUNNING, SERVER_STATUS::INIT))) {
                    $row['cluster_role'] = "";
                    if ($dbServer->GetFarmRoleObject()->GetRoleObject()->getDbMsrBehavior() || $dbServer->GetFarmRoleObject()->GetRoleObject()->hasBehavior(ROLE_BEHAVIORS::MYSQL)) {

                        $isMaster = ($dbServer->GetProperty(SERVER_PROPERTIES::DB_MYSQL_MASTER) || $dbServer->GetProperty(Scalr_Db_Msr::REPLICATION_MASTER));
                        $row['cluster_role'] = ($isMaster) ? 'Master' : 'Slave';

                        if ($isMaster && $dbServer->GetFarmRoleObject()->GetSetting(Scalr_Db_Msr::SLAVE_TO_MASTER) || $dbServer->GetFarmRoleObject()->GetSetting(DBFarmRole::SETTING_MYSQL_SLAVE_TO_MASTER)) {
                            $row['cluster_role'] = 'Promoting';
                        }
                    }
                }

                $row['cloud_location'] = $dbServer->GetCloudLocation();
                if ($dbServer->platform == SERVER_PLATFORMS::EC2) {
                    $loc = $dbServer->GetProperty(EC2_SERVER_PROPERTIES::AVAIL_ZONE);
                    if ($loc && $loc != 'x-scalr-diff')
                        $row['cloud_location'] .= "/".substr($loc, -1, 1);
                }

                if ($dbServer->platform == SERVER_PLATFORMS::EC2) {
                    $row['has_eip'] = $this->db->GetOne("SELECT id FROM elastic_ips WHERE server_id = ?", array($dbServer->serverId));
                }

                if ($dbServer->GetFarmRoleObject()->GetRoleObject()->hasBehavior(ROLE_BEHAVIORS::MONGODB)) {
                    $shardIndex = $dbServer->GetProperty(Scalr_Role_Behavior_MongoDB::SERVER_SHARD_INDEX);
                    $replicaSetIndex = $dbServer->GetProperty(Scalr_Role_Behavior_MongoDB::SERVER_REPLICA_SET_INDEX);
                    $row['cluster_position'] = "{$shardIndex}-{$replicaSetIndex}";
                }
            }
            catch(Exception $e){  }

            if ($dbServer->status == SERVER_STATUS::RUNNING || $dbServer->status == SERVER_STATUS::SUSPENDED) {

                $rebooting = $this->db->GetOne("SELECT value FROM server_properties WHERE server_id=? AND `name`=? LIMIT 1", array(
                    $row['server_id'], SERVER_PROPERTIES::REBOOTING
                ));

                $resuming = $this->db->GetOne("SELECT value FROM server_properties WHERE server_id=? AND `name`=? LIMIT 1", array(
                    $row['server_id'], SERVER_PROPERTIES::RESUMING
                ));

                $missing = $this->db->GetOne("SELECT value FROM server_properties WHERE server_id=? AND `name`=? LIMIT 1", array(
                    $row['server_id'], SERVER_PROPERTIES::MISSING
                ));

                if ($rebooting)
                    $row['status'] = "Rebooting";

                if ($resuming)
                    $row['status'] = "Resuming";

                if ($missing)
                    $row['status'] = "Missing";

                $subStatus = $dbServer->GetProperty(SERVER_PROPERTIES::SUB_STATUS);
                if ($subStatus)
                    $row['status'] = ucfirst($subStatus);
            }

            $row['is_locked'] = $dbServer->GetProperty(EC2_SERVER_PROPERTIES::IS_LOCKED) ? 1 : 0;
            $row['is_szr'] = $dbServer->IsSupported("0.5");
            $row['initDetailsSupported'] = $dbServer->IsSupported("0.7.181");

            if ($dbServer->GetProperty(SERVER_PROPERTIES::SZR_IS_INIT_FAILED) && in_array($dbServer->status, array(SERVER_STATUS::INIT, SERVER_STATUS::PENDING)))
                $row['isInitFailed'] = 1;

            $launchError = $dbServer->GetProperty(SERVER_PROPERTIES::LAUNCH_ERROR);
            if ($launchError)
                $row['launch_error'] = "1";

            $serverAlerts = new Alerts($dbServer);

            $row['agent_version'] = $dbServer->GetProperty(SERVER_PROPERTIES::SZR_VESION);
            $row['agent_update_needed'] = $dbServer->IsSupported("0.7") && !$dbServer->IsSupported("0.7.189");
            $row['agent_update_manual'] = !$dbServer->IsSupported("0.5");
            $row['os_family'] = $dbServer->GetOsFamily();
            $row['flavor'] = $dbServer->GetFlavor();
            $row['instance_type_name'] = $dbServer->GetProperty(SERVER_PROPERTIES::INFO_INSTANCE_TYPE_NAME);
            $row['alerts'] = $serverAlerts->getActiveAlertsCount();
            if (!$row['flavor'])
                $row['flavor'] = '';

            if ($dbServer->status == SERVER_STATUS::RUNNING) {
                $tm = (int)$dbServer->GetProperty(SERVER_PROPERTIES::INITIALIZED_TIME);

                if (!$tm)
                    $tm = (int)strtotime($row['dtadded']);

                if ($tm > 0) {
                    $row['uptime'] = Scalr_Util_DateTime::getHumanReadableTimeout(time() - $tm, false);
                }
            }
            else
                $row['uptime'] = '';

            $r_dns = $this->db->GetOne("SELECT value FROM farm_role_settings WHERE farm_roleid=? AND `name`=? LIMIT 1", array(
                $row['farm_roleid'], DBFarmRole::SETTING_EXCLUDE_FROM_DNS
            ));

            $row['excluded_from_dns'] = (!$dbServer->GetProperty(SERVER_PROPERTIES::EXCLUDE_FROM_DNS) && !$r_dns) ? false : true;
        }

        $this->response->data($response);
    }

    public function xListServersUpdateAction()
    {
        if (!$this->request->isAllowed(Acl::RESOURCE_FARMS_SERVERS) && !$this->request->isAllowed(Acl::RESOURCE_FARMS_ROLES, Acl::PERM_FARMS_ROLES_CREATE)) {
            throw new Scalr_Exception_InsufficientPermissions();
        }

        $this->request->defineParams(array(
            'servers' => array('type' => 'json')
        ));

        $retval = array();
        $sql = array();


        $servers = $this->getParam('servers');
        if (!empty($servers)) {
            foreach ($servers as $serverId) {
                $sql[] = $this->db->qstr($serverId);
            }
        }

        $stmt = "
            SELECT s.server_id, s.status, s.remote_ip, s.local_ip
            FROM servers s
            LEFT JOIN farms f ON f.id = s.farm_id
            WHERE s.server_id IN (" . join($sql, ',') . ")
            AND s.env_id = ?
        ";

        $args = array($this->getEnvironmentId());

        if ($this->request->isAllowed(Acl::RESOURCE_FARMS_SERVERS)) {
            if (!$this->request->isAllowed(Acl::RESOURCE_FARMS, Acl::PERM_FARMS_NOT_OWNED_FARMS)) {
                $stmt .= " AND (f.created_by_id = ? OR s.status IN (?, ?) AND f.id IS NULL)";
                $args[] = $this->user->getId();
                $args[] = SERVER_STATUS::IMPORTING;
                $args[] = SERVER_STATUS::TEMPORARY;
            }
        } else {
            $sql .= ' AND s.status IN (?, ?)';
            $args[] = SERVER_STATUS::IMPORTING;
            $args[] = SERVER_STATUS::TEMPORARY;
        }

        if (count($sql)) {
            $servers = $this->db->Execute($stmt, $args);
            while ($server = $servers->FetchRow()) {
                $rebooting = $this->db->GetOne("SELECT value FROM server_properties WHERE server_id=? AND `name`=? LIMIT 1", array(
                    $server['server_id'], SERVER_PROPERTIES::REBOOTING
                ));
                if ($rebooting) {
                    $server['status'] = "Rebooting";
                }

                $subStatus =  $this->db->GetOne("SELECT value FROM server_properties WHERE server_id=? AND `name`=? LIMIT 1", array(
                    $server['server_id'], SERVER_PROPERTIES::SUB_STATUS
                ));
                if ($subStatus) {
                    $server['status'] = ucfirst($subStatus);
                }

                $szrInitFailed = $this->db->GetOne("SELECT value FROM server_properties WHERE server_id=? AND `name`=? LIMIT 1", array(
                    $server['server_id'], SERVER_PROPERTIES::SZR_IS_INIT_FAILED
                ));

                if ($szrInitFailed && in_array($server['status'], array(SERVER_STATUS::INIT, SERVER_STATUS::PENDING)))
                    $server['isInitFailed'] = 1;

                $launchError = $this->db->GetOne("SELECT value FROM server_properties WHERE server_id=? AND `name`=? LIMIT 1", array(
                    $server['server_id'], SERVER_PROPERTIES::LAUNCH_ERROR
                ));

                if ($launchError)
                    $server['launch_error'] = "1";

                $retval[$server['server_id']] = $server;
            }
        }

        $this->response->data(array(
            'servers' => $retval
        ));
    }

    public function xSzrUpdateAction()
    {
        $this->request->restrictAccess(Acl::RESOURCE_FARMS_SERVERS);

        if (! $this->getParam('serverId'))
            throw new Exception(_('Server not found'));

        $dbServer = DBServer::LoadByID($this->getParam('serverId'));
        $this->user->getPermissions()->validate($dbServer);

        $port = $dbServer->GetProperty(SERVER_PROPERTIES::SZR_UPDC_PORT);
        if (!$port)
            $port = 8008;

        $updateClient = new Scalr_Net_Scalarizr_UpdateClient($dbServer, $port, 100);
        $status = $updateClient->updateScalarizr();

        $this->response->success('Scalarizr successfully updated to the latest version');
    }

    public function xSzrRestartAction()
    {
        $this->request->restrictAccess(Acl::RESOURCE_FARMS_SERVERS);

        if (! $this->getParam('serverId'))
            throw new Exception(_('Server not found'));

        $dbServer = DBServer::LoadByID($this->getParam('serverId'));
        $this->user->getPermissions()->validate($dbServer);

        $port = $dbServer->GetProperty(SERVER_PROPERTIES::SZR_UPDC_PORT);
        if (!$port)
            $port = 8008;

        $updateClient = new Scalr_Net_Scalarizr_UpdateClient($dbServer, $port, 30);
        $status = $updateClient->restartScalarizr();

        $this->response->success('Scalarizr successfully restarted');
    }

    /**
     * @param DBServer $dbServer
     * @param bool $cached check only cached information
     * @param int $timeout
     * @return array|NULL
     */
    public function getServerStatus(DBServer $dbServer, $cached = true, $timeout = 0)
    {
        if ($dbServer->status == SERVER_STATUS::RUNNING && $dbServer->GetProperty(SERVER_PROPERTIES::SUB_STATUS) != 'stopped' &&
            (($dbServer->IsSupported('0.8') && $dbServer->osType == 'linux') || ($dbServer->IsSupported('0.19') && $dbServer->osType == 'windows'))) {
            if ($cached && !$dbServer->IsSupported('2.7.7')) {
                return [
                    'status' => 'statusNoCache',
                    'error' => "<span style='color:gray;'>Scalarizr is checking actual status</span>"
                ];
            }

            try {
                $port = $dbServer->GetProperty(SERVER_PROPERTIES::SZR_UPDC_PORT);
                if (!$port) {
                    $port = 8008;
                }
                if (! $timeout) {
                    $timeout = \Scalr::config('scalr.system.instances_connection_timeout');
                }

                $updateClient = new Scalr_Net_Scalarizr_UpdateClient($dbServer, $port, $timeout);
                $scalarizr = $updateClient->getStatus($cached);

                try {
                    if ($dbServer->farmRoleId != 0) {
                        $scheduledOn = $dbServer->GetFarmRoleObject()->GetSetting('scheduled_on');
                    }
                } catch (Exception $e) {}

                $nextUpdate = null;
                if ($scalarizr->candidate && $scalarizr->installed != $scalarizr->candidate) {
                    $nextUpdate = [
                        'candidate'   => htmlspecialchars($scalarizr->candidate),
                        'scheduledOn' => $scheduledOn ? Scalr_Util_DateTime::convertTzFromUTC($scheduledOn) : null
                    ];
                }
                return [
                    'status'      => htmlspecialchars($scalarizr->service_status),
                    'version'     => htmlspecialchars($scalarizr->installed),
                    'candidate'   => htmlspecialchars($scalarizr->candidate),
                    'repository'  => ucfirst(htmlspecialchars($scalarizr->repository)),
                    'lastUpdate'  => [
                        'date'        => ($scalarizr->executed_at) ? Scalr_Util_DateTime::convertTzFromUTC($scalarizr->executed_at) : "",
                        'error'       => nl2br(htmlspecialchars($scalarizr->error))
                    ],
                    'nextUpdate'  => $nextUpdate,
                    'fullInfo'    => $scalarizr
                ];
            } catch (Exception $e) {
                if (stristr($e->getMessage(), "Method not found")) {
                    return ['status' => 'upgradeUpdClient'];
                } else {
                    return [
                        'status' => 'statusNotAvailable',
                        'error'  => "<span style='color:red;'>Scalarizr status is not available: {$e->getMessage()}</span>"
                    ];
                }
            }
        }
    }

    /**
     * @param string $serverId
     * @param int $timeout
     * @throws Exception
     */
    public function xGetServerRealStatusAction($serverId, $timeout = 30)
    {
        if (!$this->request->isAllowed(Acl::RESOURCE_FARMS_SERVERS) && !$this->request->isAllowed(Acl::RESOURCE_FARMS_ROLES, Acl::PERM_FARMS_ROLES_CREATE)) {
            throw new Scalr_Exception_InsufficientPermissions();
        }

        if (! $serverId) {
            throw new Exception(_('Server not found'));
        }

        $dbServer = DBServer::LoadByID($serverId);
        $this->user->getPermissions()->validate($dbServer);

        $this->response->data([
            'scalarizr' => $this->getServerStatus($dbServer, false, $timeout)
        ]);
    }

    public function dashboardAction()
    {
        if (!$this->request->isAllowed(Acl::RESOURCE_FARMS_SERVERS) && !$this->request->isAllowed(Acl::RESOURCE_FARMS_ROLES, Acl::PERM_FARMS_ROLES_CREATE)) {
            throw new Scalr_Exception_InsufficientPermissions();
        }

        if (! $this->getParam('serverId')) {
            throw new Exception(_('Server not found'));
        }

        $dbServer = DBServer::LoadByID($this->getParam('serverId'));
        $this->user->getPermissions()->validate($dbServer);

        $data = array();

        $p = PlatformFactory::NewPlatform($dbServer->platform);
        $info = $p->GetServerExtendedInformation($dbServer, true);
        if (is_array($info) && count($info)) {
            $data['cloudProperties'] = $info;

            if ($dbServer->platform == SERVER_PLATFORMS::OPENSTACK) {
                $client = $p->getOsClient($this->environment, $dbServer->GetCloudLocation());
                $iinfo = $client->servers->getServerDetails($dbServer->GetProperty(OPENSTACK_SERVER_PROPERTIES::SERVER_ID));
                $data['raw_server_info'] = $iinfo;
            }
        }

        $imageIdDifferent = false;
        try {
            $dbRole = $dbServer->GetFarmRoleObject()->GetRoleObject();
            // GCE didn't have imageID before we implement this feature
            $imageIdDifferent = ($dbRole->__getNewRoleObject()->getImage($dbServer->platform, $dbServer->cloudLocation)->imageId != $dbServer->imageId) && $dbServer->imageId;
        } catch (Exception $e) {}


        $r_dns = $this->db->GetOne("SELECT value FROM farm_role_settings WHERE farm_roleid=? AND `name`=? LIMIT 1", array(
            $dbServer->farmRoleId, DBFarmRole::SETTING_EXCLUDE_FROM_DNS
        ));

        $conf = $this->getContainer()->config->get('scalr.load_statistics.connections.plotter');

        try {
            if ($dbServer->farmRoleId != 0) {
                $hostNameFormat = $dbServer->GetFarmRoleObject()->GetSetting(Scalr_Role_Behavior::ROLE_BASE_HOSTNAME_FORMAT);
                $hostnameDebug = (!empty($hostNameFormat)) ? $dbServer->applyGlobalVarsToValue($hostNameFormat) : '';
            }
        } catch (Exception $e) {}

        if ($dbServer->farmId != 0) {
            $hash = $dbServer->GetFarmObject()->Hash;
        }

        $instType = $dbServer->GetProperty(SERVER_PROPERTIES::INFO_INSTANCE_TYPE_NAME);
        if (empty($instType)) {
            $instType = PlatformFactory::NewPlatform($dbServer->platform)->GetServerFlavor($dbServer);
        }
        $data['general'] = array(
            'server_id'         => $dbServer->serverId,
            'hostname_debug'    => urlencode($hostnameDebug),
            'hostname'          => $dbServer->GetProperty(Scalr_Role_Behavior::SERVER_BASE_HOSTNAME),
            'farm_id'           => $dbServer->farmId,
            'farm_name'         => $dbServer->farmId ? $dbServer->GetFarmObject()->Name : "",
            'farm_roleid'       => $dbServer->farmRoleId,
            'imageId'           => $dbServer->imageId,
            'imageIdDifferent'  => $imageIdDifferent,
            'farm_hash'         => $hash,
            'role_id'           => isset($dbRole) ? $dbRole->id : null,
            'platform'          => $dbServer->platform,
            'cloud_location'    => $dbServer->GetCloudLocation(),
            'role'              => array (
                                    'name'      => isset($dbRole) ? $dbRole->name : 'unknown',
                                    'id'        => isset($dbRole) ? $dbRole->id : 0,
                                    'platform'  => $dbServer->platform
                                ),
            'os'                => array(
                                    'title'  => isset($dbRole) ? $dbRole->os : 'unknown',
                                    'family' => isset($dbRole) ? $dbRole->osFamily : 'unknown'
                                ),
            'behaviors'         => isset($dbRole) ? $dbRole->getBehaviors() : array(),
            'status'            => $dbServer->status,
            'initDetailsSupported' => $dbServer->IsSupported("0.7.181"),
            'index'             => $dbServer->index,
            'local_ip'          => $dbServer->localIp,
            'remote_ip'         => $dbServer->remoteIp,
            'instType'          => $instType,
            'addedDate'         => Scalr_Util_DateTime::convertTz($dbServer->dateAdded),
            'excluded_from_dns' => (!$dbServer->GetProperty(SERVER_PROPERTIES::EXCLUDE_FROM_DNS) && !$r_dns) ? false : true,
            'is_locked'         => $dbServer->GetProperty(EC2_SERVER_PROPERTIES::IS_LOCKED) ? 1 : 0,
            'cloud_server_id'   => $dbServer->GetCloudServerID(),
            'monitoring_host_url' => "{$conf['scheme']}://{$conf['host']}:{$conf['port']}",
            'debug' 			=> $p->debugLog
        );

        $szrInitFailed = $this->db->GetOne("SELECT value FROM server_properties WHERE server_id=? AND `name`=? LIMIT 1", array(
            $dbServer->serverId, SERVER_PROPERTIES::SZR_IS_INIT_FAILED
        ));

        if ($szrInitFailed && in_array($dbServer->status, array(SERVER_STATUS::INIT, SERVER_STATUS::PENDING))) {
            $data['general']['isInitFailed'] = 1;
        }

        if ($dbServer->GetProperty(SERVER_PROPERTIES::LAUNCH_ERROR)) {
            $data['general']['launch_error'] = 1;
        }

        if ($dbServer->status == SERVER_STATUS::RUNNING) {
            $rebooting = $this->db->GetOne("SELECT value FROM server_properties WHERE server_id=? AND `name`=? LIMIT 1", array(
                $dbServer->serverId, SERVER_PROPERTIES::REBOOTING
            ));
            if ($rebooting) {
                $data['general']['status'] = "Rebooting";
            }

            $subStatus = $dbServer->GetProperty(SERVER_PROPERTIES::SUB_STATUS);
            if ($subStatus) {
                $data['general']['status'] = ucfirst($subStatus);
            }
        }

        $status = $this->getServerStatus($dbServer, true);
        if ($status) {
            $data['scalarizr'] = $status;
        }

        $internalProperties = $dbServer->GetAllProperties();
        if (!empty($internalProperties)) {
            $data['internalProperties'] = $internalProperties;
        }

        if (!$dbServer->IsSupported('0.5'))
        {
            $baseurl = $this->getContainer()->config('scalr.endpoint.scheme') . "://" .
                       $this->getContainer()->config('scalr.endpoint.host');

            $authKey = $dbServer->GetKey();
            if (!$authKey) {
                $authKey = Scalr::GenerateRandomKey(40);
                $dbServer->SetProperty(SERVER_PROPERTIES::SZR_KEY, $authKey);
            }

            $dbServer->SetProperty(SERVER_PROPERTIES::SZR_KEY_TYPE, SZR_KEY_TYPE::PERMANENT);
            $data['updateAmiToScalarizr'] = sprintf("wget " . $baseurl . "/storage/scripts/amiscripts-to-scalarizr.py && python amiscripts-to-scalarizr.py -s %s -k %s -o queryenv-url=%s -o messaging_p2p.producer_url=%s",
                $dbServer->serverId,
                $authKey,
                $baseurl . "/query-env",
                $baseurl . "/messaging"
            );
        }

        $this->response->page('ui/servers/dashboard.js', $data, array('ui/servers/actionsmenu.js', 'ui/monitoring/window.js'));
    }

    public function consoleOutputAction()
    {
        if (!$this->request->isAllowed(Acl::RESOURCE_FARMS_SERVERS) && !$this->request->isAllowed(Acl::RESOURCE_FARMS_ROLES, Acl::PERM_FARMS_ROLES_CREATE)) {
            throw new Scalr_Exception_InsufficientPermissions();
        }

        if (! $this->getParam('serverId')) {
            throw new Exception(_('Server not found'));
        }

        $dbServer = DBServer::LoadByID($this->getParam('serverId'));
        $this->user->getPermissions()->validate($dbServer);

        $output = PlatformFactory::NewPlatform($dbServer->platform)->GetServerConsoleOutput($dbServer);

        if ($output) {
            $output = trim(base64_decode($output));
            $output = str_replace("\t", "&nbsp;&nbsp;&nbsp;&nbsp;", $output);
            $output = nl2br($output);

            $output = str_replace("\033[74G", "</span>", $output);
            $output = str_replace("\033[39;49m", "</span>", $output);
            $output = str_replace("\033[80G <br />", "<span style='padding-left:20px;'></span>", $output);
            $output = str_replace("\033[80G", "<span style='padding-left:20px;'>&nbsp;</span>", $output);
            $output = str_replace("\033[31m", "<span style='color:red;'>", $output);
            $output = str_replace("\033[33m", "<span style='color:brown;'>", $output);
        } else
            $output = 'Console output not available yet';

        $this->response->page('ui/servers/consoleoutput.js', array(
            'name' => $dbServer->serverId,
            'content' => $output
        ));
    }

    public function xServerExcludeFromDnsAction()
    {
        $this->request->restrictAccess(Acl::RESOURCE_FARMS_SERVERS);

        if (! $this->getParam('serverId'))
            throw new Exception(_('Server not found'));

        $dbServer = DBServer::LoadByID($this->getParam('serverId'));
        $this->user->getPermissions()->validate($dbServer);

        $dbServer->SetProperty(SERVER_PROPERTIES::EXCLUDE_FROM_DNS, 1);

        $zones = DBDNSZone::loadByFarmId($dbServer->farmId);
        foreach ($zones as $DBDNSZone)
        {
            $DBDNSZone->updateSystemRecords($dbServer->serverId);
            $DBDNSZone->save();
        }

        $this->response->success("Server successfully removed from DNS");
    }

    public function xServerIncludeInDnsAction()
    {
        $this->request->restrictAccess(Acl::RESOURCE_FARMS_SERVERS);

        if (! $this->getParam('serverId'))
            throw new Exception(_('Server not found'));

        $dbServer = DBServer::LoadByID($this->getParam('serverId'));
        $this->user->getPermissions()->validate($dbServer);

        $dbServer->SetProperty(SERVER_PROPERTIES::EXCLUDE_FROM_DNS, 0);

        $zones = DBDNSZone::loadByFarmId($dbServer->farmId);
        foreach ($zones as $DBDNSZone)
        {
            $DBDNSZone->updateSystemRecords($dbServer->serverId);
            $DBDNSZone->save();
        }

        $this->response->success("Server successfully added to DNS");
    }

    public function xServerCancelAction()
    {
        $this->request->restrictAccess(Acl::RESOURCE_FARMS_SERVERS);

        if (! $this->getParam('serverId'))
            throw new Exception(_('Server not found'));

        $dbServer = DBServer::LoadByID($this->getParam('serverId'));
        $this->user->getPermissions()->validate($dbServer);

        $bt_id = $this->db->GetOne("
            SELECT id FROM bundle_tasks
            WHERE server_id=? AND prototype_role_id='0' AND status NOT IN (?,?,?)
            LIMIT 1
        ", array(
            $dbServer->serverId,
            SERVER_SNAPSHOT_CREATION_STATUS::FAILED,
            SERVER_SNAPSHOT_CREATION_STATUS::SUCCESS,
            SERVER_SNAPSHOT_CREATION_STATUS::CANCELLED
        ));

        if ($bt_id) {
            $BundleTask = BundleTask::LoadById($bt_id);
            $BundleTask->SnapshotCreationFailed("Server was cancelled before snapshot was created.");
        }

        if ($dbServer->status == SERVER_STATUS::IMPORTING) {
            $dbServer->Remove();
        } else {
            $dbServer->terminate(DBServer::TERMINATE_REASON_OPERATION_CANCELLATION, true, $this->user);
        }

        $this->response->success("Server successfully cancelled and removed from database.");
    }

    public function xResumeServersAction()
    {
        $this->request->restrictAccess(Acl::RESOURCE_FARMS_SERVERS);

        $this->request->defineParams(array(
            'servers' => array('type' => 'json')
        ));

        $errorServers = array();

        foreach ($this->getParam('servers') as $serverId) {
            try {
                $dbServer = DBServer::LoadByID($serverId);
                $this->user->getPermissions()->validate($dbServer);

                if ($dbServer->platform == SERVER_PLATFORMS::EC2 || PlatformFactory::isOpenstack($dbServer->platform)) {
                    PlatformFactory::NewPlatform($dbServer->platform)->ResumeServer($dbServer);
                } else {
                    //NOT SUPPORTED
                }
            }
            catch (Exception $e) {}
        }

        $this->response->data(array('data' => $errorServers));
    }

    public function xSuspendServersAction()
    {
        $this->request->restrictAccess(Acl::RESOURCE_FARMS_SERVERS);

        $this->request->defineParams(array(
            'servers' => array('type' => 'json')
        ));

        $errorServers = array();

        foreach ($this->getParam('servers') as $serverId) {
            try {
                $dbServer = DBServer::LoadByID($serverId);
                $this->user->getPermissions()->validate($dbServer);

                if ($dbServer->platform == SERVER_PLATFORMS::EC2 || PlatformFactory::isOpenstack($dbServer->platform)) {
                    $dbServer->suspend('', false, $this->user);
                } else {
                    //NOT SUPPORTED
                }
            }
            catch (Exception $e) {}
        }

        $this->response->data(array('data' => $errorServers));
    }

    public function xServerRebootServersAction()
    {
        $this->request->restrictAccess(Acl::RESOURCE_FARMS_SERVERS);

        $this->request->defineParams(array(
            'servers' => array('type' => 'json'),
            'type'
        ));

        $type = $this->getParam('type') ? $this->getParam('type') : 'hard';

        foreach ($this->getParam('servers') as $serverId) {
            try {
                $dbServer = DBServer::LoadByID($serverId);
                $this->user->getPermissions()->validate($dbServer);

                if ($type == 'hard'/* || PlatformFactory::isOpenstack($dbServer->platform)*/) {
                    $isSoft = $type == 'hard' ? false : true;
                    PlatformFactory::NewPlatform($dbServer->platform)->RebootServer($dbServer, $isSoft);
                } else {
                    try {
                        $dbServer->scalarizr->system->reboot();
                    } catch (Exception $e) {
                        $errorServers[] = $dbServer->serverId;
                        $errorMessages[] = $e->getMessage();
                    }

                    $debug = $dbServer->scalarizr->system->debug;
                }
            }
            catch (Exception $e) {}
        }

        $this->response->data(array('data' => $errorServers, 'errorMessage' => $errorMessages, 'debug' => $debug));
    }

    public function xServerTerminateServersAction()
    {
        $this->request->restrictAccess(Acl::RESOURCE_FARMS_SERVERS);

        $this->request->defineParams(array(
            'servers' => array('type' => 'json'),
            'descreaseMinInstancesSetting' => array('type' => 'bool'),
            'forceTerminate' => array('type' => 'bool')
        ));

        foreach ($this->getParam('servers') as $serverId) {
            $dbServer = DBServer::LoadByID($serverId);
            $this->user->getPermissions()->validate($dbServer);

            $forceTerminate = !$dbServer->isOpenstack() && !$dbServer->isCloudstack() && $this->getParam('forceTerminate');

            if ($dbServer->GetProperty(EC2_SERVER_PROPERTIES::IS_LOCKED))
                continue;

            if (!$forceTerminate) {
                Logger::getLogger(LOG_CATEGORY::FARM)->info(new FarmLogMessage($dbServer->farmId,
                    sprintf("Scheduled termination for server %s (%s). It will be terminated in 3 minutes.",
                        $dbServer->serverId,
                        $dbServer->remoteIp ? $dbServer->remoteIp : $dbServer->localIp
                    )
                ));
            }

            $dbServer->terminate(array(DBServer::TERMINATE_REASON_MANUALLY, $this->user->fullname), (bool)$forceTerminate, $this->user);
        }

        if ($this->getParam('descreaseMinInstancesSetting')) {
            try {
                $servers = $this->getParam('servers');
                $dbServer = DBServer::LoadByID($servers[0]);
                $dbFarmRole = $dbServer->GetFarmRoleObject();
            } catch (Exception $e) {}

            if ($dbFarmRole) {
                $minInstances = $dbFarmRole->GetSetting(DBFarmRole::SETTING_SCALING_MIN_INSTANCES);
                if ($minInstances > count($servers)) {
                    $dbFarmRole->SetSetting(DBFarmRole::SETTING_SCALING_MIN_INSTANCES,
                        $minInstances - count($servers),
                        DBFarmRole::TYPE_LCL
                    );
                } else {
                    if ($minInstances != 0)
                        $dbFarmRole->SetSetting(DBFarmRole::SETTING_SCALING_MIN_INSTANCES, 1, DBFarmRole::TYPE_CFG);
                }
            }
        }

        $this->response->success();
    }

    public function xServerGetLaAction()
    {
        $this->request->restrictAccess(Acl::RESOURCE_FARMS_SERVERS);

        $dbServer = DBServer::LoadByID($this->getParam('serverId'));
        $this->user->getPermissions()->validate($dbServer);

        if (!$dbServer->IsSupported('0.13.0')) {
            $la = "Unknown";
        } else {
            if ($dbServer->osType == 'linux') {
                try {
                    $la = $dbServer->scalarizr->system->loadAverage();
                    if ($la[0] !== null && $la[0] !== false)
                        $la = number_format($la[0], 2);
                    else
                        $la = "Unknown";
                } catch (Exception $e) {
                    $la = "Unknown";
                }
            } else
                $la = "Not available";
        }

        $this->response->data(array('la' => $la));
    }

    public function createSnapshotAction()
    {
        $this->request->restrictAccess(Acl::RESOURCE_FARMS_ROLES, Acl::PERM_FARMS_ROLES_CREATE);

        if (!$this->getParam('serverId'))
            throw new Exception(_('Server not found'));

        $dbServer = DBServer::LoadByID($this->getParam('serverId'));
        $this->user->getPermissions()->validate($dbServer);

        $dbFarmRole = $dbServer->GetFarmRoleObject();

        if ($dbFarmRole->GetRoleObject()->hasBehavior(ROLE_BEHAVIORS::MYSQL)) {
            $this->response->warning("You are about to synchronize MySQL instance. The bundle will not include DB data. <a href='#/db/dashboard?farmId={$dbServer->farmId}&type=mysql'>Click here if you wish to bundle and save DB data</a>.", true);

            if (!$dbServer->GetProperty(SERVER_PROPERTIES::DB_MYSQL_MASTER)) {
                $dbSlave = true;
            }
        }

        $dbMsrBehavior = $dbFarmRole->GetRoleObject()->getDbMsrBehavior();
        if ($dbMsrBehavior) {
            $this->response->warning("You are about to synchronize DB instance. The bundle will not include DB data. <a href='#/db/manager/dashboard?farmId={$dbServer->farmId}&type={$dbMsrBehavior}'>Click here if you wish to bundle and save DB data</a>.", true);

            if (!$dbServer->GetProperty(Scalr_Db_Msr::REPLICATION_MASTER)) {
                $dbSlave = true;
            }
        }

        //Check for already running bundle on selected instance
        $chk = $this->db->GetOne("SELECT id FROM bundle_tasks WHERE server_id=? AND status NOT IN ('success', 'failed') LIMIT 1",
            array($dbServer->serverId)
        );

        if ($chk) {
            $this->response->failure(sprintf(_("This server is already synchonizing. <a href='#/bundletasks/%s/logs'>Check status</a>."), $chk), true);
            return;
        }

        if (!$dbServer->IsSupported("0.2-112"))
            throw new Exception(sprintf(_("You cannot create snapshot from selected server because scalr-ami-scripts package on it is too old.")));

        //Check is role already synchronizing...
        $chk = $this->db->GetRow("SELECT id, server_id FROM bundle_tasks WHERE prototype_role_id=? AND status NOT IN ('success', 'failed') LIMIT 1", array(
            $dbServer->GetFarmRoleObject()->RoleID
        ));

        if ($chk && ($chk['server_id'] != $dbServer->serverId)) {
            try {
                $bDBServer = DBServer::LoadByID($chk['server_id']);
            }
            catch(Exception $e) {}

            if ($bDBServer->farmId == $dbServer->farmId) {
                $this->response->failure(sprintf(_("This role is already synchonizing. <a href='#/bundletasks/%s/logs'>Check status</a>."), $chk['id']), true);
                return;
            }
        }

        $roleImage = $dbFarmRole->GetRoleObject()->__getNewRoleObject()->getImage($dbServer->platform, $dbServer->GetCloudLocation());
        $image = $roleImage->getImage();
        $roleName = $dbServer->GetFarmRoleObject()->GetRoleObject()->name;
        $this->response->page('ui/servers/createsnapshot.js', array(
            'serverId' 	=> $dbServer->serverId,
            'platform'	=> $dbServer->platform,
            'dbSlave'	=> $dbSlave,
            'isVolumeSizeSupported' => $dbServer->platform == SERVER_PLATFORMS::EC2 && (
                $dbServer->IsSupported('0.7') && $dbServer->osType == 'linux' ||
                $image->isEc2HvmImage()
            ),
            'isVolumeTypeSupported' => $dbServer->platform == SERVER_PLATFORMS::EC2 && (
                $dbServer->IsSupported('2.11.4') && $dbServer->osType == 'linux' ||
                $image->isEc2HvmImage()
            ),
            'farmId' => $dbServer->farmId,
            'farmName' => $dbServer->GetFarmObject()->Name,
            'roleName' => $roleName,
            'imageId' => $dbServer->imageId,
            'roleImageId' => $roleImage->imageId,
            'imageName' => $image->name,
            'isSharedRole' => $dbFarmRole->GetRoleObject()->envId == 0,
            'cloudLocation' => $dbServer->GetCloudLocation(),
            'replaceNoReplace' => "<b>DO NOT REPLACE</b> any roles on any farms, just create a new one.</td>",
            'replaceFarmReplace' => "Replace the role '{$roleName}' with this new one <b>ONLY</b> on the current farm '{$dbServer->GetFarmObject()->Name}'</td>",
            'replaceAll' => "Replace the role '{$roleName}' with this new one on <b>ALL MY FARMS</b> <br/><span style=\"font-style:italic;font-size:11px;\">(You will be able to bundle this role with the same name. The old role will be renamed.)</span></td>"
        ));
    }

    /**
     * @param   string  $serverId
     * @param   string  $name
     * @param   string  $description
     * @param   bool    $createRole
     * @param   string  $replaceRole
     * @param   bool    $replaceImage
     * @param   int     $rootVolumeSize
     * @param   string  $rootVolumeType
     * @param   int     $rootVolumeIops
     * @throws Exception
     */
    public function xServerCreateSnapshotAction($serverId, $name = '', $description = '', $createRole = false, $replaceRole = '', $replaceImage = false, $rootVolumeSize = 0, $rootVolumeType = '', $rootVolumeIops = 0)
    {
        $this->request->restrictAccess(Acl::RESOURCE_FARMS_ROLES, Acl::PERM_FARMS_ROLES_CREATE);

        if (! $serverId)
            throw new Exception('Server not found');

        $dbServer = DBServer::LoadByID($serverId);
        $this->user->getPermissions()->validate($dbServer);

        $errorMsg = [];
        //Check for already running bundle on selected instance
        $chk = $this->db->GetOne("SELECT id FROM bundle_tasks WHERE server_id=? AND status NOT IN ('success', 'failed') LIMIT 1",
            array($dbServer->serverId)
        );

        if ($chk)
            $errorMsg[] = sprintf(_("Server '%s' is already synchonizing."), $dbServer->serverId);

        //Check is role already synchronizing...
        $chk = $this->db->GetOne("SELECT server_id FROM bundle_tasks WHERE prototype_role_id=? AND status NOT IN ('success', 'failed') LIMIT 1", array(
            $dbServer->GetFarmRoleObject()->RoleID
        ));

        if ($chk && $chk != $dbServer->serverId) {
            try	{
                $bDBServer = DBServer::LoadByID($chk);
                if ($bDBServer->farmId == $dbServer->farmId)
                    $errorMsg[] = sprintf(_("Role '%s' is already synchonizing."), $dbServer->GetFarmRoleObject()->GetRoleObject()->name);
            } catch(Exception $e) {}
        }

        if ($dbServer->GetFarmRoleObject()->NewRoleID)
            $errorMsg[] = sprintf(_("Role '%s' is already synchonizing."), $dbServer->GetFarmRoleObject()->GetRoleObject()->name);

        if (! empty($errorMsg))
            throw new Exception(implode('\n', $errorMsg));

        $validator = new \Scalr\UI\Request\Validator();
        $validator->addErrorIf(strlen($name) < 3, 'name', _("Role name should be greater than 3 chars"));
        $validator->addErrorIf(! preg_match("/^[A-Za-z0-9-]+$/si", $name), 'name', _("Role name is incorrect"));
        $validator->addErrorIf(! in_array($replaceRole, ['farm', 'all', '']), 'replaceRole', 'Invalid value');

        $object = $createRole ? BundleTask::BUNDLETASK_OBJECT_ROLE : BundleTask::BUNDLETASK_OBJECT_IMAGE;
        $replaceType = SERVER_REPLACEMENT_TYPE::NO_REPLACE;

        if ($createRole) {
            if ($replaceRole == 'farm')
                $replaceType = SERVER_REPLACEMENT_TYPE::REPLACE_FARM;
            else if ($replaceRole == 'all')
                $replaceType = SERVER_REPLACEMENT_TYPE::REPLACE_ALL;
        } else {
            if ($replaceImage && $dbServer->GetFarmRoleObject()->GetRoleObject()->envId != 0)
                $replaceType = SERVER_REPLACEMENT_TYPE::REPLACE_ALL;
        }

        if ($createRole) {
            $roleInfo = $this->db->GetRow("SELECT * FROM roles WHERE name=? AND (env_id=? OR env_id='0') LIMIT 1", array($name, $dbServer->envId, $dbServer->GetFarmRoleObject()->RoleID));
            if ($roleInfo) {
                if ($roleInfo['env_id'] == 0) {
                    $validator->addError('name', _("Selected role name is reserved and cannot be used for custom role"));
                } else if ($replaceType != SERVER_REPLACEMENT_TYPE::REPLACE_ALL) {
                    $validator->addError('name', _("Specified role name is already used by another role. You can use this role name only if you will replace old one on ALL your farms."));
                } else if ($replaceType == SERVER_REPLACEMENT_TYPE::REPLACE_ALL && $roleInfo['id'] != $dbServer->GetFarmRoleObject()->RoleID) {
                    $validator->addError('name', _("This Role name is already in use. You cannot replace a Role different from the one you are currently snapshotting."));
                }
            }
        }

        $roleImage = $dbServer->GetFarmRoleObject()->GetRoleObject()->__getNewRoleObject()->getImage($dbServer->platform, $dbServer->GetCloudLocation());
        $rootBlockDevice = [];
        if ($dbServer->platform == SERVER_PLATFORMS::EC2 && ($dbServer->IsSupported('0.7') && $dbServer->osType == 'linux' || $roleImage->getImage()->isEc2HvmImage())) {
            if ($rootVolumeSize > 0) {
                $rootBlockDevice['size'] = $rootVolumeSize;
            }

            if (in_array($rootVolumeType, ['standard', 'gp2', 'io1'])) {
                $rootBlockDevice['volume_type'] = $rootVolumeType;
                if ($rootVolumeType == 'io1' && $rootVolumeIops > 0) {
                    $rootBlockDevice['iops'] = $rootVolumeIops;
                }
            }
        }

        if (! $validator->isValid($this->response))
            return;

        $ServerSnapshotCreateInfo = new ServerSnapshotCreateInfo(
            $dbServer,
            $name,
            $replaceType,
            $object,
            $description,
            $rootBlockDevice
        );
        $BundleTask = BundleTask::Create($ServerSnapshotCreateInfo);

        $BundleTask->createdById = $this->user->id;
        $BundleTask->createdByEmail = $this->user->getEmail();

        if ($dbServer->GetFarmRoleObject()->GetSetting('user-data.scm_branch') == 'feature/image-api') {
            $BundleTask->generation = 2;
        }

        $protoRole = DBRole::loadById($dbServer->GetFarmRoleObject()->RoleID);

        $BundleTask->osFamily = $protoRole->osFamily;
        $BundleTask->osVersion = $protoRole->osVersion;

        if (in_array($protoRole->osFamily, array('redhat', 'oel', 'scientific')) &&
            $dbServer->platform == SERVER_PLATFORMS::EC2) {
            $BundleTask->bundleType = SERVER_SNAPSHOT_CREATION_TYPE::EC2_EBS_HVM;
        }

        $BundleTask->save();

        $this->response->success("Bundle task successfully created. <a href='#/bundletasks/{$BundleTask->id}/logs'>Click here to check status.</a>", true);
    }

    public function xServerDeleteAction($serverId)
    {
        $this->request->restrictAccess(Acl::RESOURCE_FARMS_SERVERS);

        $dbServer = DBServer::LoadByID($serverId);
        $this->user->getPermissions()->validate($dbServer);

        if ($dbServer->status == SERVER_STATUS::PENDING_TERMINATE || $dbServer->status == SERVER_STATUS::TERMINATED) {
            $serverHistory = $dbServer->getServerHistory();
            if ($serverHistory) {
                $serverHistory->setTerminated();
            }
            $dbServer->Remove();
        }

        $this->response->success('Server record successfully removed.');
    }
}
