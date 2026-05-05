<?php

namespace Everest\Console\Commands\Extensions;

class EasyUpdateCommand extends UpdateExtensionCommand
{
    protected $signature = 'update
                            {source? : Extension id from a configured repository, or a local package file path}
                            {--path= : Explicit path to a local .M12LabsExtension file}
                            {--repository= : Repository slug or numeric id for repository updates}
                            {--release= : Specific repository version to update to}
                            {--file : Prefer local package-file update mode}
                            {--label= : Stored source label for manual file updates}
                            {--yes : Skip interactive prompts when possible}
                            {--debug : Show detailed update diagnostics}';

    protected $description = 'Update an installed M12Labs extension from the current directory, a package file, or the configured repository.';
}
