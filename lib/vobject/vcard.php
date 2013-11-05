<?php
/**
 * ownCloud - VCard component
 *
 * This component represents the BEGIN:VCARD and END:VCARD found in every
 * vcard.
 *
 * @author Thomas Tanghus
 * @author Evert Pot (http://www.rooftopsolutions.nl/)
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Contacts\VObject;

use OCA\Contacts\Utils;
use Sabre\VObject;

/**
 * This class overrides \Sabre\VObject\Component\VCard::validate() to be add
 * to import partially invalid vCards by ignoring invalid lines and to
 * validate and upgrade using ....
*/
class VCard extends VObject\Component\VCard {

	const THUMBNAIL_PREFIX = 'contact-thumbnail-';
	const THUMBNAIL_SIZE = 28;

	/**
	* The following constants are used by the validate() method.
	*/
    const REPAIR = 1;
	const UPGRADE = 2;

	/**
	 * The groups in the contained properties
	 *
	 * @var array
	 */
	protected $groups = array();

	/**
	* VCards with version 2.1, 3.0 and 4.0 are found.
	*
	* If the VCARD doesn't know its version, 3.0 is assumed and if
	* option UPGRADE is given it will be upgraded to version 3.0.
	*/
	const DEFAULT_VERSION = '3.0';

	/**
	* The vCard 2.1 specification allows parameter values without a name.
	* The parameter name is then determined from the unique parameter value.
	* In version 2.1 e.g. a phone can be formatted like: TEL;HOME;CELL:123456789
	* This has to be changed to either TEL;TYPE=HOME,CELL:123456789 or TEL;TYPE=HOME;TYPE=CELL:123456789 - both are valid.
	*
	* From: https://github.com/barnabywalters/vcard/blob/master/barnabywalters/VCard/VCard.php
	*
	* @param string value
	* @return string
	*/
	protected function paramName($value) {
		static $types = array (
				'DOM', 'INTL', 'POSTAL', 'PARCEL','HOME', 'WORK',
				'PREF', 'VOICE', 'FAX', 'MSG', 'CELL', 'PAGER',
				'BBS', 'MODEM', 'CAR', 'ISDN', 'VIDEO',
				'AOL', 'APPLELINK', 'ATTMAIL', 'CIS', 'EWORLD',
				'INTERNET', 'IBMMAIL', 'MCIMAIL',
				'POWERSHARE', 'PRODIGY', 'TLX', 'X400',
				'GIF', 'CGM', 'WMF', 'BMP', 'MET', 'PMB', 'DIB',
				'PICT', 'TIFF', 'PDF', 'PS', 'JPEG', 'QTIME',
				'MPEG', 'MPEG2', 'AVI',
				'WAVE', 'AIFF', 'PCM',
				'X509', 'PGP');
		static $values = array (
				'INLINE', 'URL', 'CID');
		static $encodings = array (
				'7BIT', 'QUOTED-PRINTABLE', 'BASE64');
		$name = 'UNKNOWN';
		if (in_array($value, $types)) {
			$name = 'TYPE';
		} elseif (in_array($value, $values)) {
			$name = 'VALUE';
		} elseif (in_array($value, $encodings)) {
			$name = 'ENCODING';
		}
		return $name;
	}

	/**
	* Decode properties for upgrading from v. 2.1
	*
	* @param Sabre_VObject_Property $property Reference to a \Sabre\VObject\Property.
	* The only encoding allowed in version 3.0 is 'b' for binary. All encoded strings
	* must therefore be decoded and the parameters removed.
	*/
	protected function decodeProperty(&$property) {
		foreach($property->parameters as $key=>&$parameter) {
			// Check for values without names which Sabre interprets
			// as names without values.
			if(trim($parameter->value) === '') {
				$parameter->value = $parameter->name;
				$parameter->name = $this->paramName($parameter->name);
			}
			// Check out for encoded string and decode them :-[
			if(strtoupper($parameter->name) == 'ENCODING') {
				if(strtoupper($parameter->value) == 'QUOTED-PRINTABLE') {
					$property->value = str_replace(
						"\r\n", "\n",
						VObject\StringUtil::convertToUTF8(
							quoted_printable_decode($property->value)
						)
					);
					unset($property->parameters[$key]);
				} elseif(strtoupper($parameter->value) == 'BASE64') {
					$parameter->value = 'b';
				}
			} elseif(strtoupper($parameter->name) == 'CHARSET') {
				unset($property->parameters[$key]);
			}
		}
	}

	/**
	* Work around issue in older VObject sersions
	* https://github.com/fruux/sabre-vobject/issues/24
	*
	* @param Sabre_VObject_Property $property Reference to a Sabre_VObject_Property.
	*/
	public function fixPropertyParameters(&$property) {
		// Work around issue in older VObject sersions
		// https://github.com/fruux/sabre-vobject/issues/24
		foreach($property->parameters as $key=>$parameter) {
			$delim = '';
			if(strpos($parameter->value, ',') === false) {
				continue;
			}
			$values = explode(',', $parameter->value);
			$values = array_map('trim', $values);
			$parameter->value = array_shift($values);
			foreach($values as $value) {
				$property->add($parameter->name, $value);
			}
		}
	}

	/**
	* Validates the node for correctness.
	*
	* The following options are supported:
	*   - VCard::REPAIR - If something is broken, and automatic repair may
	*                    be attempted.
	*   - VCard::UPGRADE - If needed the vCard will be upgraded to version 3.0.
	*
	* An array is returned with warnings.
	*
	* Every item in the array has the following properties:
	*    * level - (number between 1 and 3 with severity information)
	*    * message - (human readable message)
	*    * node - (reference to the offending node)
	*
	* @param int $options
	* @return array
	*/
	public function validate($options = 0) {

		$warnings = array();

		if ($options & self::UPGRADE) {
			$this->VERSION = self::DEFAULT_VERSION;
			foreach($this->children as &$property) {
				$this->decodeProperty($property);
				$this->fixPropertyParameters($property);
				/* What exactly was I thinking here?
				switch((string)$property->name) {
					case 'LOGO':
					case 'SOUND':
					case 'PHOTO':
						if(isset($property['TYPE']) && strpos((string)$property['TYPE'], '/') === false) {
							$property['TYPE'] = 'image/' . strtolower($property['TYPE']);
						}
				}*/
			}
		}

		$version = $this->select('VERSION');
		if (count($version) !== 1) {
			$warnings[] = array(
				'level' => 1,
				'message' => 'The VERSION property must appear in the VCARD component exactly 1 time',
				'node' => $this,
			);
			if ($options & self::REPAIR) {
				$this->VERSION = self::DEFAULT_VERSION;
				if (!$options & self::UPGRADE) {
					$options |= self::UPGRADE;
				}
			}
		} else {
			$version = (string)$this->VERSION;
			if ($version!=='2.1' && $version!=='3.0' && $version!=='4.0') {
				$warnings[] = array(
					'level' => 1,
					'message' => 'Only vcard version 4.0 (RFC6350), version 3.0 (RFC2426) or version 2.1 (icm-vcard-2.1) are supported.',
					'node' => $this,
				);
				if ($options & self::REPAIR) {
					$this->VERSION = self::DEFAULT_VERSION;
					if (!$options & self::UPGRADE) {
						$options |= self::UPGRADE;
					}
				}
			}

		}
		$fn = $this->select('FN');
		if (count($fn) !== 1) {
			$warnings[] = array(
				'level' => 1,
				'message' => 'The FN property must appear in the VCARD component exactly 1 time',
				'node' => $this,
			);
			if (($options & self::REPAIR) && count($fn) === 0) {
				// We're going to try to see if we can use the contents of the
				// N property.
				if (isset($this->N)) {
					$value = explode(';', (string)$this->N);
					if (isset($value[1]) && $value[1]) {
						$this->FN = $value[1] . ' ' . $value[0];
					} else {
						$this->FN = $value[0];
					}
				// Otherwise, the ORG property may work
				} elseif (isset($this->ORG)) {
					$this->FN = (string)$this->ORG;
				} elseif (isset($this->EMAIL)) {
					$this->FN = (string)$this->EMAIL;
				}

			}
		}

		$n = $this->select('N');
		if (count($n) !== 1) {
			$warnings[] = array(
				'level' => 1,
				'message' => 'The N property must appear in the VCARD component exactly 1 time',
				'node' => $this,
			);
			// TODO: Make a better effort parsing FN.
			if (($options & self::REPAIR) && count($n) === 0) {
				// Take 2 first name parts of 'FN' and reverse.
				$slice = array_reverse(array_slice(explode(' ', (string)$this->FN), 0, 2));
				if(count($slice) < 2) { // If not enought, add one more...
					$slice[] = "";
				}
				$this->N = implode(';', $slice).';;;';
			}
		}

		if (!isset($this->UID)) {
			$warnings[] = array(
				'level' => 1,
				'message' => 'Every vCard must have a UID',
				'node' => $this,
			);
			if ($options & self::REPAIR) {
				$this->UID = Utils\Properties::generateUID();
			}
		}

		if (($options & self::REPAIR) || ($options & self::UPGRADE)) {
			$now = new \DateTime;
			$this->REV = $now->format(\DateTime::W3C);
		}

		return array_merge(
			parent::validate($options),
			$warnings
		);

	}

	/**
	 * Get all group names in the vCards properties
	 * @return array
	 */
	public function propertyGroups() {
		foreach($this->children as $property) {
			if($property->group && !isset($this->groups[$property->group])) {
				$this->groups[] = $property->group;
			}
		}
		if(count($this->groups) > 1) {
			sort($this->groups);
		}
		return $this->groups;
	}

	// TODO: Cleanup these parameters and move method to Utils class
	public function cacheThumbnail(\OCP\Image $image = null, $remove = false, $update = false) {
		$key = self::THUMBNAIL_PREFIX . $this->combinedKey();
		//\OC_Cache::remove($key);
		if(\OC_Cache::hasKey($key) && $image === null && $remove === false && $update === false) {
			return \OC_Cache::get($key);
		}
		if($remove) {
			\OC_Cache::remove($key);
			if(!$update) {
				return false;
			}
		}
		if(is_null($image)) {
			$this->retrieve();
			$image = new \OCP\Image();
			if(!isset($this->PHOTO) && !isset($this->LOGO)) {
				return false;
			}
			if(!$image->loadFromBase64((string)$this->PHOTO)) {
				if(!$image->loadFromBase64((string)$this->LOGO)) {
					return false;
				}
			}
		}
		if(!$image->centerCrop()) {
			\OCP\Util::writeLog('contacts',
				__METHOD__ .'. Couldn\'t crop thumbnail for ID ' . $key,
				\OCP\Util::ERROR);
			return false;
		}
		if(!$image->resize(self::THUMBNAIL_SIZE)) {
			\OCP\Util::writeLog('contacts',
				__METHOD__ . '. Couldn\'t resize thumbnail for ID ' . $key,
				\OCP\Util::ERROR);
			return false;
		}
		 // Cache as base64 for around a month
		\OC_Cache::set($key, strval($image), 3000000);
		\OCP\Util::writeLog('contacts', 'Caching ' . $key, \OCP\Util::DEBUG);
		return \OC_Cache::get($key);
	}

}