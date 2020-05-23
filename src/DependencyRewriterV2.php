<?php

/**
 * @see       https://github.com/laminas/laminas-dependency-plugin for the canonical source repository
 * @copyright https://github.com/laminas/laminas-dependency-plugin/blob/master/COPYRIGHT.md
 * @license   https://github.com/laminas/laminas-dependency-plugin/blob/master/LICENSE.md New BSD License
 */

namespace Laminas\DependencyPlugin;

use Composer\Composer;
use Composer\Console\Application;
use Composer\DependencyResolver\Operation;
use Composer\Factory;
use Composer\Installer\PackageEvent;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\PackageInterface;
use Composer\Plugin\PrePoolCreateEvent;
use Composer\Repository\InstalledFilesystemRepository;
use Composer\Script\Event;
use Symfony\Component\Console\Input\ArrayInput;

use function assert;
use function call_user_func;
use function dirname;
use function get_class;
use function in_array;
use function is_array;
use function ksort;
use function sprintf;

final class DependencyRewriterV2 extends AbstractDependencyRewriter implements
    PoolCapableInterface,
    AutoloadDumpCapableInterface
{
    /**
     * @var PackageInterface[]
     */
    private $zendPackagesInstalled = [];

    /**
     * @var callable
     */
    private $applicationFactory;

    /**
     * @var string
     */
    private $composerFile;

    /**
     * @param string $composerFile
     */
    public function __construct(callable $applicationFactory = null, $composerFile = '')
    {
        parent::__construct();
        $this->composerFile = $composerFile ?: Factory::getComposerFile();
        $this->applicationFactory = $applicationFactory ?: static function () {
            return new Application();
        };
    }

    /**
     * Ensure nested dependencies on ZF packages install equivalent Laminas packages.
     *
     * When a 3rd party package has dependencies on ZF packages, this method
     * will detect the request to install a ZF package, and rewrite it to use a
     * Laminas variant at the equivalent version, if one exists.
     */
    public function onPrePackageInstallOrUpdate(PackageEvent $event)
    {
        $this->output(sprintf('<info>In %s</info>', __METHOD__), IOInterface::DEBUG);
        $operation = $event->getOperation();

        switch (true) {
            case $operation instanceof Operation\InstallOperation:
                $package = $operation->getPackage();
                break;
            case $operation instanceof Operation\UpdateOperation:
                $package = $operation->getTargetPackage();
                break;
            default:
                // Nothing to do
                $this->output(sprintf(
                    '<info>Exiting; operation of type %s not supported</info>',
                    get_class($operation)
                ), IOInterface::DEBUG);
                return;
        }

        $name = $package->getName();
        if (! $this->isZendPackage($name)) {
            // Nothing to do
            $this->output(sprintf(
                '<info>Exiting; package "%s" does not have a replacement</info>',
                $name
            ), IOInterface::DEBUG);
            return;
        }

        $replacementName = $this->transformPackageName($name);
        if ($replacementName === $name) {
            // Nothing to do
            $this->output(sprintf(
                '<info>Exiting; while package "%s" is a ZF package, it does not have a replacement</info>',
                $name
            ), IOInterface::DEBUG);
            return;
        }

        $version = $package->getVersion();
        $replacementPackage = $this->composer->getRepositoryManager()->findPackage($replacementName, $version);

        if ($replacementPackage === null) {
            // No matching replacement package found
            $this->output(sprintf(
                '<info>Exiting; no replacement package found for package "%s" with version %s</info>',
                $replacementName,
                $version
            ), IOInterface::DEBUG);
            return;
        }

        $this->output(sprintf(
            '<info>Could replace package %s with package %s, using version %s</info>',
            $name,
            $replacementName,
            $version
        ), IOInterface::VERBOSE);

        $this->zendPackagesInstalled[] = $package;
    }

    public function onPostAutoloadDump(Event $event)
    {
        if (! $this->zendPackagesInstalled) {
            return;
        }

        // Remove zend-packages from vendor/ directory
        $composer = $event->getComposer();
        $installers = $composer->getInstallationManager();
        $repository = $composer->getRepositoryManager()->getLocalRepository();

        $composerFile = $this->createComposerFile();
        $definition = $composerFile->read();
        assert(is_array($definition));
        $definitionChanged = false;

        foreach ($this->zendPackagesInstalled as $package) {
            $packageName = $package->getName();
            $replacementName = $this->transformPackageName($packageName);
            if ($this->isRootRequirement($definition, $packageName)) {
                $this->output(sprintf(
                    '<info>Package %s is a root requirement. laminas-dependency-plugin changes your composer.json'
                    . ' to require laminas equivalent directly!</info>',
                    $packageName
                ));

                $definitionChanged = true;
                $definition = $this->updateRootRequirements(
                    $definition,
                    $packageName,
                    $replacementName
                );
            }

            $uninstallOperation = new Operation\UninstallOperation($package);
            $installers->uninstall($repository, $uninstallOperation);
        }

        if ($definitionChanged) {
            $composerFile->write($definition);
        }

        $this->updateLockFile();
    }

    public function onPrePoolCreate(PrePoolCreateEvent $event)
    {
        $this->output(sprintf('In %s', __METHOD__));

        $installedRepository = $this->createInstalledRepository($this->composer, $this->io);
        $installedPackages = $installedRepository->getPackages();

        $installedZendPackages = [];

        foreach ($installedPackages as $package) {
            if (! $this->isZendPackage($package->getName())) {
                continue;
            }

            $installedZendPackages[] = $package->getName();
        }

        if (! $installedZendPackages) {
            return;
        }

        $unacceptableFixedPackages = $event->getUnacceptableFixedPackages();
        $repository = $this->composer->getRepositoryManager();
        $packages = $event->getPackages();

        foreach ($packages as $index => $package) {
            if (! in_array($package->getName(), $installedZendPackages, true)) {
                continue;
            }

            $replacement = $this->transformPackageName($package->getName());
            if ($replacement === $package->getName()) {
                continue;
            }

            $replacementPackage = $repository->findPackage($replacement, $package->getVersion());
            if (! $replacementPackage instanceof PackageInterface) {
                continue;
            }

            $unacceptableFixedPackages[] = $package;

            $this->output(sprintf('Slipstreaming %s => %s', $package->getName(), $replacement));
            $packages[$index] = $replacementPackage;
        }

        $event->setUnacceptableFixedPackages($unacceptableFixedPackages);
        $event->setPackages($packages);
    }

    /**
     * With `composer update --lock`, all missing packages are being installed aswell.
     * This is where we slip-stream in with our plugin.
     */
    private function updateLockFile()
    {
        $application = call_user_func($this->applicationFactory);
        assert($application instanceof Application);

        $application->setAutoExit(false);

        $input = [
            'command' => 'update',
            '--lock' => true,
            '--no-scripts' => true,
            '--working-dir' => dirname($this->composerFile),
        ];

        $application->run(new ArrayInput($input));
    }

    /**
     * @return InstalledFilesystemRepository
     */
    private function createInstalledRepository(Composer $composer, IOInterface $io)
    {
        $vendor = $composer->getConfig()->get('vendor-dir');

        return new InstalledFilesystemRepository(
            new JsonFile($vendor . '/composer/installed.json', null, $io),
            true,
            $composer->getPackage()
        );
    }

    /**
     * @param string $packageName
     * @return bool
     */
    private function isRootRequirement(array $definition, $packageName)
    {
        return isset($definition['require'][$packageName]) || isset($definition['require-dev'][$packageName]);
    }

    /**
     * @param string $packageName
     * @param string $replacementPackageName
     * @return array
     */
    private function updateRootRequirements(array $definition, $packageName, $replacementPackageName)
    {
        $sortPackages = false;
        if (isset($definition['config']['sort-packages'])) {
            $sortPackages = $definition['config']['sort-packages'];
        }

        foreach (['require', 'require-dev'] as $key) {
            if (! isset($definition[$key])) {
                continue;
            }

            $requirements = $definition[$key];
            if (! isset($requirements[$packageName])) {
                continue;
            }

            $requirements[$replacementPackageName] = $requirements[$packageName];
            unset($requirements[$packageName]);
            if ($sortPackages) {
                ksort($requirements);
            }

            $definition[$key] = $requirements;
        }

        return $definition;
    }

    /**
     * @return PackageInterface[]
     */
    public function getZendPackagesInstalled()
    {
        return $this->zendPackagesInstalled;
    }

    /**
     * @return JsonFile
     */
    private function createComposerFile()
    {
        return new JsonFile($this->composerFile, null, $this->io);
    }
}
