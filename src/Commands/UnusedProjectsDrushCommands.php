<?php

namespace Drupal\unused_projects\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Core\Extension\ModuleExtensionList;
use Drush\Commands\DrushCommands;
use Drush\Utils\StringUtils;

class UnusedProjectsDrushCommands extends DrushCommands {

  protected $extensionListModule;

  public function __construct(ModuleExtensionList $extensionListModule) {
    parent::__construct();
    $this->extensionListModule = $extensionListModule;
  }

  /**
   * @return \Drupal\Core\Extension\ModuleExtensionList
   */
  public function getExtensionListModule() {
    return $this->extensionListModule;
  }

  /**
   * Shows a list of unused projects and their modules
   *
   * Original pm-list shows individual modules and themes, without grouping the output
   * by project. This command is more focused on projects, with ability to show
   * solo modules as well.
   *
   * @command wf:unused-projects
   * @option status Only show extensions having a given status. Choices: enabled or disabled. Defaults to disabled.
   * @otpion hide-modules Hide solo modules.
   * @option subs Show submodules.
   *
   * @field-labels
   *   project: Project
   *   display_name: Name
   *   name: Name
   *   path: Path
   *   status: Status
   *   version: Version
   * @default-fields project,display_name,status,version,path
   * @aliases un-p
   * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
   */
  public function projectList($options = ['format' => 'table', 'status' => 'disabled', 'subs' => false, 'hide-modules' => false]) {
    $rows = [];

    // Get all extensions
    $modules = $this->getExtensionListModule()->getList();
    // Filter unwanted entries
    $this->filterModules($modules);
    // Group by project
    $modules = $this->groupExtensions($modules);

    // Get options
    $status_filter = StringUtils::csvToArray(strtolower($options['status']));
    $showSubs = !empty($options['subs']);
    $showModules = !$options['hide-modules'];

    // Prepare output
    foreach ($modules as $key => $item) {

      $isProject = is_array($item);
      $main = !$isProject ? $item : ($item[$key] ?? reset($item));

      // Filter out based on "--modules" options
      if (!$isProject && !$showModules) {
        continue;
      }

      // Filter out by status
      $status = $this->extensionStatus($item);
      if (!in_array($status, $status_filter)) {
        continue;
      }

      $project_name = isset($main->info['project']) ? $main->info['project'] : $main->getName();
      $main_name = ' ('. $main->getName(). ')';
      $rowIsModule =  !$showSubs || ($main->getName() === $project_name);
      // Now as we have a set of things to show - format rows
      $row = [
        'project' => $project_name,
        'display_name' => $main->info['name'] . ( $rowIsModule ? $main_name : '' ),
        'name' => $main->getName(),
        'status' => $rowIsModule ? ucfirst($status) : '',
        // Suppress notice when version is not present.
        'version' => $rowIsModule ? @$main->info['version'] : '',
        'path' => $rowIsModule ? $main->getPath() : '',
      ];
      $rows[$key] = $row;

      // Now, add additional rows for each sub-module, if requested
      if ($isProject && $showSubs) {
        unset($item[$key]);
        foreach ($item as $name => $sub) {
          $status = $this->extensionStatus($sub);
          $row = [
            'project' => '',
            'display_name' => "- " . $sub->info['name']. ' ('. $name. ')',
            'name' => $name,
            'path' => $sub->getPath(),
            'status' => ucfirst($status),
            // Suppress notice when version is not present.
            'version' => @$sub->info['version'],
          ];
          $rows["{$key}:{$name}"] = $row;
        }
      }
    }


    return new RowsOfFields($rows);

  }

  /**
   * Filters out unwanted results:
   * - core modules
   * - test modules
   * @param $modules
   */
  private function filterModules(&$modules) {
    $modules = array_filter($modules, function($m) {
      return 'core' !== $m->origin && 'module' === $m->getType() && false === strpos($m->getPath(), 'tests');
    });
  }

  /**
   * Checks extension status
   *
   * @param $extension
   *   Object of a single extension info, or array of objects
   *
   * @return
   *   Array|String describing extension status. Values: enabled|disabled.
   */
  public function extensionStatus($item) {
    if (!is_array($item)) {
      return $item->status == 1 ? 'enabled' : 'disabled';
    }
    $count = 0;
    foreach ($item as $entry) {
      $count += $entry->status;
    }
    return $count > 0 ? 'enabled' : 'disabled';
  }

  /**
   * Groups extensions by project
   *
   * @param $extensions array list of extensions
   * @return array Grouped extensions
   */
  private function groupExtensions($extensions) {
    // Detected projects
    $groups = [];
    // Modules to analyze more
    $orphans = [];
    // Start with what should be already known - project property
    // NOTE: Project folder matches project name
    foreach ($extensions as $extension) {
      $name = $extension->getName();
      // If extension has project property set - add to project group
      if (!empty($extension->info["project"])) {
        $groups[$extension->info["project"]][$name] = $extension;
      }
      // If no project info available - store extension for later processing
      else {
        // Store orphan extensions keyed by their path
        $orphans[$extension->subpath] = $extension;
      }
    }

    // Process orphans
    ksort($orphans);
    foreach ($orphans as $path => $item) {
      // Go over each path slug from the last to the first,
      // and try to find project with that name
      $found = false;
      foreach (array_reverse(explode("/", $path)) as $slug) {
        if (!isset($groups[$slug])) {
          continue;
        }
        $group = &$groups[$slug];
        // When project group found, make sure $extension is a part of that project
        // Get project path
        $project_path = $this->findProjectPath($group, $slug);
        // Check if extension's paths starts with project's path
        // Add trailing "/" to make sure we check the whole slug in the end
        if (0 !== strpos($path . "/", $project_path . "/")) {
          continue;
        }
        // Bingo! Add extension to the project group
        // But first make sure we convert solo module into a project
        if (!is_array($group)) {
          $main = $group;
          $group = [
            $main->getName() => $main
          ];
        }
        $group[$item->getName()] = $item;
        $found = true;
      }
      // If still not found - it is a project itself
      if (!$found) {
        $groups[basename($path)] = $item;
      }
    }
    ksort($groups);
    return $groups;
  }

  private function findProjectPath($modules, $project_name) {
    // Solo modules like this
    if (!is_array($modules)) {
      return $modules->subpath;
    }
    // If there is module sharing project name - that is the main module,
    // return its path
    if (isset($modules[$project_name])) {
      return $modules[$project_name]->subpath;
    }
    // Otherwise, find the shortest path off all modules in a given project
    $path = null;
    foreach ($modules as $module) {
      if (null === $path || strlen($path) > strlen($module->subpath)) {
        $path = $module->subpath;
      }
    }
    return $path;
  }
}
