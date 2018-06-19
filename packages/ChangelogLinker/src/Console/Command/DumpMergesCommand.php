<?php declare(strict_types=1);

namespace Symplify\ChangelogLinker\Console\Command;

use Nette\Utils\Strings;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symplify\ChangelogLinker\Analyzer\IdsAnalyzer;
use Symplify\ChangelogLinker\ChangelogLinker;
use Symplify\ChangelogLinker\ChangeTree\Change;
use Symplify\ChangelogLinker\ChangeTree\ChangeFactory;
use Symplify\ChangelogLinker\ChangeTree\ChangeSorter;
use Symplify\ChangelogLinker\Console\Output\DumpMergesReporter;
use Symplify\ChangelogLinker\Exception\MissingPlaceholderInChangelogException;
use Symplify\ChangelogLinker\Github\GithubApi;
use Symplify\PackageBuilder\Console\Command\CommandNaming;
use Symplify\PackageBuilder\Reflection\PrivatesAccessor;

/**
 * @inspired by https://github.com/weierophinney/changelog_generator
 */
final class DumpMergesCommand extends Command
{
    /**
     * @inspiration markdown comment: https://gist.github.com/jonikarppinen/47dc8c1d7ab7e911f4c9#gistcomment-2109856
     * @var string
     */
    private const CHANGELOG_PLACEHOLDER_TO_WRITE = '<!-- changelog-linker -->';

    /**
     * @var string
     */
    private const OPTION_IN_CATEGORIES = 'in-categories';

    /**
     * @var string
     */
    private const OPTION_IN_PACKAGES = 'in-packages';

    /**
     * @var string
     */
    private const OPTION_TOKEN = 'token';

    /**
     * @var string
     */
    private const OPTION_IN_TAGS = 'in-tags';

    /**
     * @var string
     */
    private const OPTION_DRY_RUN = 'dry-run';

    /**
     * @var string
     */
    private const OPTION_LINKIFY = 'linkify';

    /**
     * @var GithubApi
     */
    private $githubApi;

    /**
     * @var SymfonyStyle
     */
    private $symfonyStyle;

    /**
     * @var ChangeSorter
     */
    private $changeSorter;

    /**
     * @var IdsAnalyzer
     */
    private $idsAnalyzer;

    /**
     * @var DumpMergesReporter
     */
    private $dumpMergesReporter;

    /**
     * @var ChangeFactory
     */
    private $changeFactory;

    /**
     * @var Change[]
     */
    private $changes = [];

    /**
     * @var ChangelogLinker
     */
    private $changelogLinker;

    public function __construct(
        GithubApi $githubApi,
        SymfonyStyle $symfonyStyle,
        ChangeSorter $changeSorter,
        IdsAnalyzer $idsAnalyzer,
        DumpMergesReporter $dumpMergesReporter,
        ChangeFactory $changeFactory,
        ChangelogLinker $changelogLinker
    ) {
        parent::__construct();
        $this->changeFactory = $changeFactory;
        $this->githubApi = $githubApi;
        $this->symfonyStyle = $symfonyStyle;
        $this->changeSorter = $changeSorter;
        $this->idsAnalyzer = $idsAnalyzer;
        $this->dumpMergesReporter = $dumpMergesReporter;
        $this->changelogLinker = $changelogLinker;
    }

    protected function configure(): void
    {
        $this->setName(CommandNaming::classToName(self::class));
        $this->setDescription(
            'Scans repository merged PRs, that are not in the CHANGELOG.md yet, and dumps them in changelog format.'
        );
        $this->addOption(
            self::OPTION_IN_CATEGORIES,
            null,
            InputOption::VALUE_NONE,
            'Print in Added/Changed/Fixed/Removed - detected from "Add", "Fix", "Removed" etc. keywords in merge title.'
        );

        $this->addOption(
            self::OPTION_IN_PACKAGES,
            null,
            InputOption::VALUE_NONE,
            'Print in groups in package names - detected from "[PackageName]" in merge title.'
        );

        $this->addOption(
            self::OPTION_IN_TAGS,
            null,
            InputOption::VALUE_NONE,
            'Print withs tags - detected from date of merge.'
        );

        $this->addOption(
            self::OPTION_DRY_RUN,
            null,
            InputOption::VALUE_NONE,
            'Print out to the output instead of writing directly into CHANGELOG.md.'
        );

        $this->addOption(
            self::OPTION_TOKEN,
            null,
            InputOption::VALUE_REQUIRED,
            'Github Token to overcome request limit.'
        );

        $this->addOption(self::OPTION_LINKIFY, null, InputOption::VALUE_NONE, 'Decorate content with links.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $highestIdInChangelog = $this->idsAnalyzer->getHighestIdInChangelog(getcwd() . '/CHANGELOG.md');

        if ($input->getOption(self::OPTION_TOKEN)) {
            $this->githubApi->authorizeWithToken($input->getOption(self::OPTION_TOKEN));
        }

        $pullRequests = $this->githubApi->getClosedPullRequestsSinceId($highestIdInChangelog);
        if (count($pullRequests) === 0) {
            $this->symfonyStyle->note(
                sprintf('There are no new pull requests to be added since ID "%d".', $highestIdInChangelog)
            );

            // success
            return 0;
        }

        foreach ($pullRequests as $pullRequest) {
            $this->changes[] = $this->changeFactory->createFromPullRequest($pullRequest);
        }

        $sortPriority = $this->getSortPriority($input);

        $sortedChanges = $this->changeSorter->sortByCategoryAndPackage($this->changes, $sortPriority);
        $sortedChanges = $this->changeSorter->sortByTags($sortedChanges);

        $content = $this->dumpMergesReporter->reportChangesWithHeadlines(
            $sortedChanges,
            $input->getOption(self::OPTION_IN_CATEGORIES),
            $input->getOption(self::OPTION_IN_PACKAGES),
            $input->getOption(self::OPTION_IN_TAGS),
            $sortPriority
        );

        if ($input->getOption(self::OPTION_LINKIFY)) {
            $content = $this->changelogLinker->processContent($content);
        }

        if ($input->getOption(self::OPTION_DRY_RUN)) {
            $this->symfonyStyle->writeln($content);
        } else {
            $this->updateChangelogContent($content);
        }

        // success
        return 0;
    }

    /**
     * Detects the order in which "--in-packages" and "--in-categories" are both called.
     * The first has a priority.
     */
    private function getSortPriority(InputInterface $input): ?string
    {
        $rawOptions = (new PrivatesAccessor())->getPrivateProperty($input, 'options');

        $requiredOptions = ['in-packages', 'in-categories'];

        if (count(array_intersect($requiredOptions, array_keys($rawOptions))) !== count($requiredOptions)) {
            return null;
        }

        foreach ($rawOptions as $name => $value) {
            if ($name === 'in-packages') {
                return 'packages';
            }

            return 'categories';
        }

        return null;
    }

    private function ensurePlaceholderIsPresent(string $changelogContent): void
    {
        if (Strings::contains($changelogContent, self::CHANGELOG_PLACEHOLDER_TO_WRITE)) {
            return;
        }

        throw new MissingPlaceholderInChangelogException(sprintf(
            'There is missing "%s" placeholder in CHANGELOG.md. Put it where you want to add dumped merges.',
            self::CHANGELOG_PLACEHOLDER_TO_WRITE
        ));
    }

    private function updateChangelogContent(string $newContent): void
    {
        $changelogContent = file_get_contents(getcwd() . '/CHANGELOG.md');

        $this->ensurePlaceholderIsPresent($changelogContent);

        $contentToWrite = sprintf(
            '%s%s%s<!-- dumped content start -->%s%s<!-- dumped content end -->%s',
            self::CHANGELOG_PLACEHOLDER_TO_WRITE,
            PHP_EOL,
            PHP_EOL,
            PHP_EOL,
            $newContent,
            PHP_EOL
        );

        $updatedChangelogContent = str_replace(
            self::CHANGELOG_PLACEHOLDER_TO_WRITE,
            $contentToWrite,
            $changelogContent
        );

        file_put_contents(getcwd() . '/CHANGELOG.md', $updatedChangelogContent);

        $this->symfonyStyle->success('The CHANGELOG.md was updated');
    }
}