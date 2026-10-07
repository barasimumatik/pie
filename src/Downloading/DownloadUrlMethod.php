<?php

declare(strict_types=1);

namespace Php\Pie\Downloading;

use Composer\Package\CompletePackageInterface;
use Php\Pie\DependencyResolver\Package;
use Php\Pie\Platform\PrePackagedBinaryAssetName;
use Php\Pie\Platform\PrePackagedSourceAssetName;
use Php\Pie\Platform\TargetPlatform;
use Php\Pie\Platform\WindowsExtensionAssetName;

use function array_key_exists;
use function array_merge;
use function assert;
use function is_string;
use function method_exists;

/** @internal This is not public API for PIE, so should not be depended upon unless you accept the risk of BC breaks */
enum DownloadUrlMethod: string
{
    private const COMPOSER_PACKAGE_EXTRA_KEY = 'download-url-method';

    case ComposerDefaultDownload   = 'composer-default';
    case WindowsBinaryDownload     = 'windows-binary';
    case PrePackagedSourceDownload = 'pre-packaged-source';
    case PrePackagedBinary         = 'pre-packaged-binary';

    /** @return non-empty-list<non-empty-string>|null */
    public function possibleAssetNames(Package $package, TargetPlatform $targetPlatform): array|null
    {
        return match ($this) {
            self::WindowsBinaryDownload => WindowsExtensionAssetName::zipNames($targetPlatform, $package),
            self::PrePackagedSourceDownload => PrePackagedSourceAssetName::packageNames($package),
            self::ComposerDefaultDownload => null,
            self::PrePackagedBinary => PrePackagedBinaryAssetName::packageNames($targetPlatform, $package),
        };
    }

    public static function fromDownloadedPackage(DownloadedPackage $downloadedPackage): self
    {
        return self::fromComposerPackage($downloadedPackage->package->composerPackage());
    }

    public static function fromComposerPackage(CompletePackageInterface $completePackage): self
    {
        $extra = $completePackage->getExtra();

        return self::from(array_key_exists(self::COMPOSER_PACKAGE_EXTRA_KEY, $extra) && is_string($extra[self::COMPOSER_PACKAGE_EXTRA_KEY]) ? $extra[self::COMPOSER_PACKAGE_EXTRA_KEY] : '');
    }

    public function writeToComposerPackage(CompletePackageInterface $composerPackage): void
    {
        assert(method_exists($composerPackage, 'setExtra'));

        $composerPackage->setExtra(array_merge($composerPackage->getExtra(), [self::COMPOSER_PACKAGE_EXTRA_KEY => $this->value]));
    }

    /** @return non-empty-list<DownloadUrlMethod> */
    public static function possibleDownloadUrlMethodsForPackage(Package $package, TargetPlatform $targetPlatform): array
    {
        // /**
        //  * PIE does not support building on Windows (yet, at least). Maintainers
        //  * should provide pre-built Windows binaries.
        //  */
        // if ($targetPlatform->operatingSystem === OperatingSystem::Windows) {
        //     return [self::WindowsBinaryDownload];
        // }

        $configuredSupportedMethods = $package->supportedDownloadUrlMethods();
        if ($configuredSupportedMethods === null) {
            return [self::ComposerDefaultDownload];
        }

        return $configuredSupportedMethods;
    }
}
