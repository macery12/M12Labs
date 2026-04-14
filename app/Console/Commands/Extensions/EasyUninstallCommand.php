<?php

namespace Everest\Console\Commands\Extensions;

class EasyUninstallCommand extends UninstallExtensionCommand
{
    protected $signature = 'uninstall
                            {extensionId : Installed extension id to remove}
                            {--force : Skip the confirmation prompt}
                            {--debug : Show detailed uninstall diagnostics}';

    protected $description = 'Uninstall an M12Labs extension package.';
}