<?php

namespace Spip\Chiffrer;

/** Override: persists cles to SSM on save() so they survive Lambda cold starts. */
final class SpipCles {
	private static array $instances = [];
	private string $file = _DIR_ETC . 'cles.php';
	private Cles $cles;

	public static function instance(string $file = ''): self {
		if (empty(self::$instances[$file])) {
			self::$instances[$file] = new self($file);
		}
		return self::$instances[$file];
	}

	public static function secret_du_site(): ?string {
		return (self::instance())->getSecretSite();
	}

	private function __construct(string $file = '') {
		if ($file) {
			$this->file = $file;
		}
		$this->cles = new Cles($this->read());
	}

	public function getSecretSite(bool $autoInit = true): ?string {
		$key = $this->getKey('secret_du_site', $autoInit);
		$meta = $this->getMetaKey('secret_du_site', $autoInit);
		return $key ^ $meta;
	}

	public function getSecretAuth(bool $autoInit = false): ?string {
		return $this->getKey('secret_des_auth', $autoInit);
	}

	public function save(): bool {
		$ok = ecrire_fichier_securise($this->file, $this->cles->toJson());
		// Persist to SSM so keys survive cold starts
		if ($ok) {
			$this->saveToSsm();
		}
		return $ok;
	}

	private function saveToSsm(): void {
		$param = getenv('SPIP_CLES_SSM_NAME');
		if (!$param) {
			$param = '/spip-serverless/' . (getenv('SPIP_ENV') ?: 'test') . '/spip/cles';
		}
		try {
			require_once '/var/task/vendor/autoload.php';
			$ssm = new \Aws\Ssm\SsmClient(['region' => getenv('AWS_REGION') ?: 'us-east-1', 'version' => 'latest']);
			$ssm->putParameter([
				'Name' => $param,
				'Value' => $this->cles->toJson(),
				'Type' => 'SecureString',
				'Overwrite' => true,
			]);
		} catch (\Throwable $e) {
			spip_log('SSM save failed: ' . $e->getMessage(), 'chiffrer' . _LOG_ERREUR);
		}
	}

	public function backup(
		#[\SensitiveParameter]
		string $withKey
	): string {
		if (count($this->cles)) {
			return Chiffrement::chiffrer($this->cles->toJson(), $withKey);
		}
		return '';
	}

	public function restore(
		string $backup,
		#[\SensitiveParameter]
		string $password_clair,
		#[\SensitiveParameter]
		string $password_hash,
		int $id_auteur
	): bool {
		if (empty($backup)) {
			return false;
		}
		$sauvegarde = Chiffrement::dechiffrer($backup, $password_clair);
		$json = json_decode($sauvegarde, true);
		if (!$json) {
			return false;
		}
		$cles_potentielles = array_map('base64_decode', $json);
		if (!empty($cles_potentielles['secret_des_auth'])) {
			if (!Password::verifier($password_clair, $password_hash, $cles_potentielles['secret_des_auth'])) {
				spip_log("Restauration de la cle `secret_des_auth` par id_auteur $id_auteur erronnee, on ignore", 'chiffrer' . _LOG_INFO_IMPORTANTE);
				unset($cles_potentielles['secret_des_auth']);
			}
		}
		$restauration = false;
		foreach ($cles_potentielles as $name => $key) {
			if (!$this->cles->has($name)) {
				$this->cles->set($name, $key);
				spip_log("Restauration de la cle $name par id_auteur $id_auteur", 'chiffrer' . _LOG_INFO_IMPORTANTE);
				$restauration = true;
			}
		}
		return $restauration;
	}

	private function getKey(string $name, bool $autoInit): ?string {
		if ($this->cles->has($name)) {
			return $this->cles->get($name);
		}
		if ($autoInit) {
			$this->cles->generate($name);
			if ($this->save()) {
				return $this->cles->get($name);
			}
			spip_log('Echec ecriture du fichier cle ' . $this->file . " ; impossible de generer une cle $name", 'chiffrer' . _LOG_ERREUR);
			$this->cles->delete($name);
		}
		return null;
	}

	private function getMetaKey(string $name, bool $autoInit = true): ?string {
		if (!isset($GLOBALS['meta'][$name])) {
			include_spip('base/abstract_sql');
			$GLOBALS['meta'][$name] = sql_getfetsel('valeur', 'spip_meta', 'nom = ' . sql_quote($name, '', 'string'));
		}
		$key = base64_decode($GLOBALS['meta'][$name] ?? '');
		if (strlen($key) === \SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
			return $key;
		}
		if (!$autoInit) {
			return null;
		}
		$key = Chiffrement::keygen();
		ecrire_meta($name, base64_encode($key), 'non');
		lire_metas();
		return $key;
	}

	private function read(): array {
		lire_fichier_securise($this->file, $json);
		if (
			$json
			and $json = \json_decode($json, true)
			and is_array($json)
		) {
			return array_map('base64_decode', $json);
		}
		return [];
	}
}
