<?php

namespace Westkingdom\HierarchicalGroupEmail;

use Robo\Result;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Robo\Collection\CollectionBuilder;

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Dumper;
use GoogleAPIExtensions\Groups;

class Commands extends \Robo\Tasks implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected $scopes = array(
        \Google_Service_Groupssettings::APPS_GROUPS_SETTINGS,

        \Google_Service_Directory::ADMIN_DIRECTORY_GROUP,
        \Google_Service_Directory::ADMIN_DIRECTORY_GROUP_READONLY,

        \Google_Service_Directory::ADMIN_DIRECTORY_GROUP_MEMBER,
        \Google_Service_Directory::ADMIN_DIRECTORY_GROUP_MEMBER_READONLY,

        \Google_Service_Directory::ADMIN_DIRECTORY_NOTIFICATIONS,

        \Google_Service_Directory::ADMIN_DIRECTORY_ORGUNIT,
        \Google_Service_Directory::ADMIN_DIRECTORY_ORGUNIT_READONLY,

        \Google_Service_Directory::ADMIN_DIRECTORY_USER,
        \Google_Service_Directory::ADMIN_DIRECTORY_USER_READONLY,

        \Google_Service_Directory::ADMIN_DIRECTORY_USER_ALIAS,
        \Google_Service_Directory::ADMIN_DIRECTORY_USER_ALIAS_READONLY,

        \Google_Service_Directory::ADMIN_DIRECTORY_USER_SECURITY,

        \Google_Service_Directory::ADMIN_DIRECTORY_USERSCHEMA,
        \Google_Service_Directory::ADMIN_DIRECTORY_USERSCHEMA_READONLY,

        \Google_Service_Calendar::CALENDAR,
        \Google_Service_Calendar::CALENDAR_READONLY,
    );

    protected function authenticate($authFile, $scopes)
    {
        $this->logger->notice('About to authenticate.');

        $authenticator = new ServiceAccountAuthenticator("Hierarchical Group Email App");
        $client = $authenticator->authenticate($authFile, $scopes);

        if (empty($client->getAccessToken())) {
            throw new \RuntimeException('Failed to authenticate.');
        }

        $this->logger->notice('Authenticated.');

        return $client;
    }

    public function fetch(
        $outputFile,
        $options =
        [
            'domain' => 'westkingdom.org',
            'auth-file' => 'service-account.yaml',
            'subdomains' => 'allyshia,champclair,crosston,cynagua,heralds,marches,mists,oertha',
        ])
    {
        $client = $this->authenticate($options['auth-file'], $this->scopes);

        $properties = [
          'subdomains' => $options['subdomains'],
        ];

        $policy = new StandardGroupPolicy($options['domain'], $properties);
        $batch = new \Google_Http_Batch($client);
        $batchWrapper = new BatchWrapper($batch);
        $controller = new GoogleAppsGroupsController($client, $batchWrapper);

        $existing = $controller->fetch($options['domain']);
        $dumper = new Dumper();
        $dumper->setIndentation(2);
        $existingAsYaml = trim($dumper->dump($existing, PHP_INT_MAX));

        file_put_contents($outputFile, $existingAsYaml);
    }

    public function sync(
        $sourceFile,
        $options =
        [
            'domain' => 'westkingdom.org',
            'state-file' => 'currentstate.westkingdom.org.yaml',
            'auth-file' => 'service-account.yaml',
            'subdomains' => 'allyshia,champclair,crosston,cynagua,heralds,marches,mists,oertha',
        ])
    {
        $client = $this->authenticate($options['auth-file'], $this->scopes);

        $groupData = file_get_contents($sourceFile);
        if (empty($groupData)) {
            throw new \Exception('Source file is empty.');
        }
        $newState = Yaml::parse($groupData);
        if (empty($newState)) {
            throw new \Exception('Source file does not contain valid yml.');
        }

        $properties = [
          'subdomains' => $options['subdomains'],
        ];

        $policy = new StandardGroupPolicy($options['domain'], $properties);
        $batch = new \Google_Http_Batch($client);
        $batchWrapper = new BatchWrapper($batch);
        $controller = new GoogleAppsGroupsController($client, $batchWrapper);

        // TODO: better location for state file
        $stateFile = $options['state-file'];
        $groupData = file_get_contents($stateFile);
        $currentState = Yaml::parse($groupData);

        $groupManager = new GroupsManager($controller, $policy, $currentState);
        $groupManager->update($newState);

        $dumper = new Dumper();
        $dumper->setIndentation(2);

        $groupManager->execute();

        $updatedState = $groupManager->export();
        $updatedStateAsYaml = trim($dumper->dump($updatedState, PHP_INT_MAX));
        // $this->io()->writeln($updatedStateAsYaml);
        file_put_contents($stateFile, $updatedStateAsYaml);
    }
}
