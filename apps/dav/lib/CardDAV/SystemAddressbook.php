<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2018, Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @author Joas Schilling <coding@schilljs.com>
 * @author Julius Härtl <jus@bitgrid.net>
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 * @author Anna Larch <anna.larch@gmx.net>
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */
namespace OCA\DAV\CardDAV;

use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use Sabre\CardDAV\Backend\BackendInterface;
use Sabre\CardDAV\Card;
use Sabre\DAV\Exception\NotFound;

class SystemAddressbook extends AddressBook {
	/** @var IConfig */
	private $config;
	private ?IUser $user;
	private ?IGroupManager $groupManager;

	public function __construct(BackendInterface $carddavBackend, array $addressBookInfo, IL10N $l10n, IConfig $config, ?IUser $user, ?IGroupManager $groupManager) {
		parent::__construct($carddavBackend, $addressBookInfo, $l10n);
		$this->config = $config;
		$this->user = $user;
		$this->groupManager = $groupManager;
	}

	/**
	 * No checkbox checked -> Show only the same user
	 * 'Allow username autocompletion in share dialog' -> show everyone
	 * 'Allow username autocompletion in share dialog' + 'Allow username autocompletion to users within the same groups' -> show only users in intersecting groups
	 * 'Allow username autocompletion in share dialog' + 'Allow username autocompletion to users based on phone number integration' -> show only the same user
	 * 'Allow username autocompletion in share dialog' + 'Allow username autocompletion to users within the same groups' + 'Allow username autocompletion to users based on phone number integration' -> show only users in intersecting groups
	 */
	public function getChildren() {
		if ($this->user === null) {
			return [];
		}
		$shareEnumeration = $this->config->getAppValue('core', 'shareapi_allow_share_dialog_user_enumeration', 'yes') === 'yes';
		$shareEnumerationGroup = $this->config->getAppValue('core', 'shareapi_restrict_user_enumeration_to_group', 'no') === 'yes';
		$shareEnumerationPhone = $this->config->getAppValue('core', 'shareapi_restrict_user_enumeration_to_phone', 'no') === 'yes';
		if (!$shareEnumeration || !$shareEnumerationGroup && $shareEnumerationPhone) {
			$name = SyncService::getCardUri($this->user);
			try {
				return [parent::getChild($name)];
			} catch (NotFound $e) {
				return [];
			}
		}

		if ($shareEnumerationGroup) {
			$groups = $this->groupManager->getUserGroups($this->user);
			$names = [];
			foreach ($groups as $group) {
				$users = $group->getUsers();
				foreach ($users as $groupUser) {
					if ($groupUser->getBackendClassName() === 'Guests') {
						continue;
					}
					$names[] = SyncService::getCardUri($this->user);
				}
			}
			return parent::getMultipleChildren($names);
		}

		$children = parent::getChildren();
		return array_filter($children, function (Card $child) {
			// check only for URIs that begin with Guests:
			return strpos($child->getName(), 'Guests:') !== 0;
		});
	}
}
