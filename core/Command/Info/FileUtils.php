<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2023 Robin Appelman <robin@icewind.nl>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OC\Core\Command\Info;


use OC\Files\SetupManager;
use OCA\Circles\MountManager\CircleMount;
use OCA\Files_External\Config\ExternalMountPoint;
use OCA\Files_Sharing\SharedMount;
use OCA\GroupFolders\Mount\GroupMountPoint;
use OCP\Constants;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\FileInfo;
use OCP\Files\IHomeStorage;
use OCP\Files\IRootFolder;
use OCP\Files\Mount\IMountManager;
use OCP\Files\Mount\IMountPoint;
use OCP\Files\Node;
use OCP\IUser;
use OCP\Share\IShare;
use OCP\Util;
use Symfony\Component\Console\Output\OutputInterface;
use OCP\Files\Folder;

class FileUtils {
	private IRootFolder $rootFolder;
	private IUserMountCache $userMountCache;
	private IMountManager $mountManager;
	private SetupManager $setupManager;

	public function __construct(
		IRootFolder $rootFolder,
		IUserMountCache $userMountCache,
		IMountManager $mountManager,
		SetupManager $setupManager
	) {
		$this->rootFolder = $rootFolder;
		$this->userMountCache = $userMountCache;
		$this->mountManager = $mountManager;
		$this->setupManager = $setupManager;
	}

	/**
	 * @param FileInfo $file
	 * @return array<string, Node[]>
	 * @throws \OCP\Files\NotPermittedException
	 * @throws \OC\User\NoUserException
	 */
	public function getFilesByUser(FileInfo $file): array {
		$id = $file->getId();
		if (!$id) {
			return [];
		}

		$mounts = $this->userMountCache->getMountsForFileId($id);
		$result = [];
		foreach ($mounts as $mount) {
			if (isset($result[$mount->getUser()->getUID()])) {
				continue;
			}

			$userFolder = $this->rootFolder->getUserFolder($mount->getUser()->getUID());
			$result[$mount->getUser()->getUID()] = $userFolder->getById($id);
		}

		return $result;
	}

	public function formatPermissions(string $type, int $permissions): string {
		if ($permissions == Constants::PERMISSION_ALL || ($type === 'file' && $permissions == (Constants::PERMISSION_ALL - Constants::PERMISSION_CREATE))) {
			return "full permissions";
		}

		$perms = [];
		$allPerms = [Constants::PERMISSION_READ => "read", Constants::PERMISSION_UPDATE => "update", Constants::PERMISSION_CREATE => "create", Constants::PERMISSION_DELETE => "delete", Constants::PERMISSION_SHARE => "share"];
		foreach ($allPerms as $perm => $name) {
			if (($permissions & $perm) === $perm) {
				$perms[] = $name;
			}
		}

		return implode(", ", $perms);
	}

	/**
	 * @psalm-suppress UndefinedClass
	 * @psalm-suppress UndefinedInterfaceMethod
	 */
	public function formatMountType(IMountPoint $mountPoint): string {
		$storage = $mountPoint->getStorage();
		if ($storage && $storage->instanceOfStorage(IHomeStorage::class)) {
			return "home storage";
		} elseif ($mountPoint instanceof SharedMount) {
			$share = $mountPoint->getShare();
			$shares = $mountPoint->getGroupedShares();
			$sharedBy = array_map(function (IShare $share) {
				$shareType = $this->formatShareType($share);
				if ($shareType) {
					return $share->getSharedBy() . " (via " . $shareType . " " . $share->getSharedWith() . ")";
				} else {
					return $share->getSharedBy();
				}
			}, $shares);
			$description = "shared by " . implode(', ', $sharedBy);
			if ($share->getSharedBy() !== $share->getShareOwner()) {
				$description .= " owned by " . $share->getShareOwner();
			}
			return $description;
		} elseif ($mountPoint instanceof GroupMountPoint) {
			return "groupfolder " . $mountPoint->getFolderId();
		} elseif ($mountPoint instanceof ExternalMountPoint) {
			return "external storage " . $mountPoint->getStorageConfig()->getId();
		} elseif ($mountPoint instanceof CircleMount) {
			return "circle";
		}
		return get_class($mountPoint);
	}

	public function formatShareType(IShare $share): ?string {
		switch ($share->getShareType()) {
			case IShare::TYPE_GROUP:
				return "group";
			case IShare::TYPE_CIRCLE:
				return "circle";
			case IShare::TYPE_DECK:
				return "deck";
			case IShare::TYPE_ROOM:
				return "room";
			case IShare::TYPE_USER:
				return null;
			default:
				return "Unknown (" . $share->getShareType() . ")";
		}
	}

	/**
	 * @param IUser $user
	 * @return IMountPoint[]
	 */
	public function getMountsForUser(IUser $user): array {
		$this->setupManager->setupForUser($user);
		$prefix = "/" . $user->getUID();
		return array_filter($this->mountManager->getAll(), function (IMountPoint $mount) use ($prefix) {
			return str_starts_with($mount->getMountPoint(), $prefix);
		});
	}

	/**
	 * Print out the largest count($sizeLimits) files in the directory tree
	 *
	 * @param OutputInterface $output
	 * @param Folder $node
	 * @param string $prefix
	 * @param array $sizeLimits largest items that are still in the queue to be printed, ordered ascending
	 * @return void
	 */
	public function outputLargeFilesTree(
		OutputInterface $output,
		Folder $node,
		string $prefix,
		array &$sizeLimits
	): int {
		$count = 0;
		$children = $node->getDirectoryListing();
		usort($children, function (Node $a, Node $b) {
			return $b->getSize() <=> $a->getSize();
		});
		foreach ($children as $i => $child) {
			if (count($sizeLimits) === 0 || $child->getSize() < $sizeLimits[0]) {
				return $count;
			}
			array_shift($sizeLimits);
			$count += 1;

//			var_dump(implode(", ", $sizeLimits));
			/** @var Node $child */
			$output->writeln("$prefix- " . $child->getName() . ": " . Util::humanFileSize($child->getSize()));
			if ($child instanceof Folder) {
				$recurseSizeLimits = $sizeLimits;
				for ($j = 0; $j < count($recurseSizeLimits); $j++) {
					$nextChildSize = (int)$children[$i + $j]?->getSize();
					if ($nextChildSize > $recurseSizeLimits[0]) {
						array_shift($recurseSizeLimits);
						$recurseSizeLimits[] = $nextChildSize;
					}
				}
				sort($recurseSizeLimits);
				$recurseCount = $this->outputLargeFilesTree($output, $child, $prefix . "  ", $recurseSizeLimits);
				$sizeLimits = array_slice($sizeLimits, $recurseCount);
				$count += $recurseCount;
			}
		}
	}
}
