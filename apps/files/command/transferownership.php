<?php
/**
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @copyright Copyright (c) 2015, ownCloud, Inc.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\Files\Command;

use OC\Files\Filesystem;
use OC\Files\View;
use OC\Share20\Share;
use OCP\Files\FileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class TransferOwnership extends Command {

	/** @var \OC\User\Manager $userManager */
	private $userManager;

	/** @var \OC\Share20\Manager */
	private $shareManager;

	/** @var FileInfo[] */
	private $allFiles = [];

	/** @var FileInfo[] */
	private $encryptedFiles = [];

	/** @var array */
	private $shares = [];

	/** @var string */
	private $sourceUser;

	/** @var string */
	private $destinationUser;

	public function __construct(\OC\User\Manager $userManager, \OC\Share20\Manager $shareManager) {
		$this->userManager = $userManager;
		$this->shareManager = $shareManager;
		parent::__construct();
	}

	protected function configure() {
		$this
			->setName('files:transfer-ownership')
			->setDescription('move file and folder from one user to another')
			->addArgument(
				'source-user',
				InputArgument::REQUIRED,
				'owner of files which shall be moved'
			)
			->addArgument(
				'destination-user',
				InputArgument::REQUIRED,
				'user who will be the new owner of the files'
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$this->sourceUser = $input->getArgument('source-user');
		$this->destinationUser = $input->getArgument('destination-user');
		if (!$this->userManager->userExists($this->sourceUser)) {
			$output->writeln("<error>Unknown source user $this->sourceUser</error>");
			return;
		}
		if (!$this->userManager->userExists($this->destinationUser)) {
			$output->writeln("<error>Unknown destination user $this->destinationUser</error>");
			return;
		}

		// setup filesystem
		Filesystem::initMountPoints($this->sourceUser);
		Filesystem::initMountPoints($this->destinationUser);

		// analyse source folder
		$this->analyse($output);

		// collect all the shares
		$this->collectUsersShares($output);

		// transfer the files
		$this->transfer($output);

		// restore the shares
		$this->restoreShares($output);
	}

	private function walkFiles(View $view, $path, \Closure $callBack) {
		foreach ($view->getDirectoryContent($path) as $fileInfo) {
			if (!$callBack($fileInfo)) {
				return;
			}
			if ($fileInfo->getType() === FileInfo::TYPE_FOLDER) {
				$this->walkFiles($view, $fileInfo->getPath(), $callBack);
			}
		}
	}

	/**
	 * @param OutputInterface $output
	 */
	protected function analyse(OutputInterface $output) {
		$view = new View();
		$output->writeln("Analysing files of $this->sourceUser ...");
		$progress = new ProgressBar($output);
		$progress->start();
		$self = $this;
		$this->walkFiles($view, "$this->sourceUser/files",
				function (FileInfo $fileInfo) use ($progress, $self) {
					if ($fileInfo->getType() === FileInfo::TYPE_FOLDER) {
						return true;
					}
					$progress->advance();
					$this->allFiles[] = $fileInfo;
					if ($fileInfo->isEncrypted()) {
						$this->encryptedFiles[] = $fileInfo;
					}
					return true;
				});
		$progress->finish();
		$output->writeln('');

		// no file is allowed to be encrypted
		if (!empty($this->encryptedFiles)) {
			$output->writeln("<error>Some files are encrypted - please decrypt them first</error>");
			foreach($this->encryptedFiles as $encryptedFile) {
				/** @var FileInfo $encryptedFile */
				$output->writeln("  " . $encryptedFile->getPath());
			}
			throw new \Exception('Execution terminated.');
		}

	}

	/**
	 * @param OutputInterface $output
	 */
	private function collectUsersShares(OutputInterface $output) {
		$output->writeln("Collecting all share information for files and folder of $this->sourceUser ...");
		$progress = new ProgressBar($output, count($this->shares));
		$page = 0;
		while(true) {
			$sharePage = $this->shareManager->getShares($this->sourceUser, $page++);
			$progress->advance(count($sharePage));
			if (empty($sharePage)) {
				break;
			}
			$this->shares = array_merge($this->shares, $sharePage);
		}

		$progress->finish();
		$output->writeln('');
	}

	/**
	 * @param OutputInterface $output
	 */
	protected function transfer(OutputInterface $output) {
		$view = new View();
		$date = date('c');
		$finalTarget = "$this->destinationUser/files/transferred from $this->sourceUser on $date";
		$output->writeln("Transferring files to $finalTarget ...");
		$view->rename("$this->sourceUser/files", $finalTarget);
	}

	/**
	 * @param OutputInterface $output
	 */
	private function restoreShares(OutputInterface $output) {
		$output->writeln("Restoring shares ...");
		$progress = new ProgressBar($output, count($this->shares));
		foreach($this->shares as $share) {
			/** @var Share $share */
			$share->setShareOwner($this->destinationUser);

			$this->shareManager->updateShare($share);
			$progress->advance();
		}
		$progress->finish();
		$output->writeln('');
	}
}
