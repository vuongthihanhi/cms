<?php
namespace Blocks;

/**
 *
 */
class InstallService extends ApplicationComponent
{
	/**
	 * Installs @@@productDisplay@@@!
	 *
	 * @param array $inputs
	 * @throws Exception
	 * @throws \Exception
	 * @return void
	 */
	public function run($inputs)
	{
		if (blx()->isInstalled())
			throw new Exception(Blocks::t('@@@productDisplay@@@ is already installed.'));

		$records = $this->_findInstallableRecords();

		// Start the transaction
		$transaction = blx()->db->beginTransaction();
		try
		{
			// Create the tables
			$this->_createTablesFromRecords($records);

			// Create the foreign keys
			$this->_createForeignKeysFromRecords($records);

			// Tell @@@productDisplay@@@ that it's installed now
			blx()->setInstalledStatus(true);

			Blocks::log('Populating the info table.', \CLogger::LEVEL_INFO);
			$this->_populateInfoTable($inputs);

			/* BLOCKSPRO ONLY */
			Blocks::log('Registering email messages.', \CLogger::LEVEL_INFO);
			$this->_registerEmailMessage('verify_email', Blocks::t('verify_email_subject'), Blocks::t('verify_email_body'));
			$this->_registerEmailMessage('verify_new_email', Blocks::t('verify_new_email_subject'), Blocks::t('verify_new_email_body'));
			$this->_registerEmailMessage('forgot_password', Blocks::t('forgot_password_subject'), Blocks::t('forgot_password_body'));
			/* end BLOCKSPRO ONLY */

			Blocks::log('Creating user.', \CLogger::LEVEL_INFO);
			$user = $this->_addUser($inputs);

			Blocks::log('Logging in user.', \CLogger::LEVEL_INFO);
			$this->_logUserIn($user, $inputs['password']);

			Blocks::log('Assigning default dashboard widgets to user.', \CLogger::LEVEL_INFO);
			blx()->dashboard->addDefaultUserWidgets();

			Blocks::log('Saving default mail settings.', \CLogger::LEVEL_INFO);
			$this->_saveDefaultMailSettings($user->email, $inputs['siteName']);

			$this->_createDefaultContent();

			Blocks::log('Finished installing... committing transaction.', \CLogger::LEVEL_INFO);
			$transaction->commit();
		}
		catch (\Exception $e)
		{
			$transaction->rollBack();
			throw $e;
		}
	}

	/**
	 * Finds installable records from the models folder.
	 *
	 * @return array
	 */
	private function _findInstallableRecords()
	{
		$records = array();
		$modelsDir = new Folder(blx()->path->getModelsPath());
		$recordFiles = $modelsDir->getContents(false, '/Record.php/');

		foreach ($recordFiles as $file)
		{
			if (IOHelper::fileExists($file))
			{
				$fileName = $file->getFileName(false);
				$class = __NAMESPACE__.'\\'.$fileName;

				// Ignore abstract classes and interfaces
				$ref = new \ReflectionClass($class);
				if ($ref->isAbstract() || $ref->isInterface())
					continue;

				$obj = new $class;

				if (method_exists($obj, 'createTable'))
					$records[] = $obj;
			}
		}

		return $records;
	}

	/**
	 * Attempts to log in the given user.
	 *
	 * @param $user
	 * @param $password
	 * @return void
	 */
	private function _logUserIn($user, $password)
	{
		$loginModel = new LoginModel();
		$loginModel->username = $user->username;
		$loginModel->password = $password;

		if (!$loginModel->login())
			Blocks::log('Could not log the user in during install.', \CLogger::LEVEL_ERROR);
	}

	/**
	 * Adds the initial user to the database.
	 *
	 * @param $inputs
	 * @return UserRecord
	 * @throws Exception
	 */
	private function _addUser($inputs)
	{
		$user = new UserRecord();
		$user->username   = $inputs['username'];
		$user->email      = $inputs['email'];
		$user->admin = true;
		/* BLOCKSPRO ONLY */
		$user->language = blx()->language;
		/* end BLOCKSPRO ONLY */
		blx()->accounts->changePassword($user, $inputs['password'], false);
		$user->save();

		if ($user->hasErrors())
		{
			$errors = $user->getErrors();
			$errorMessages = implode('.  ', $errors);
			throw new Exception(Blocks::t('There was a problem creating the user: {errorMessages}', array('errorMessages' => $errorMessages)));
		}

		return $user;
	}

	/**
	 * Creates the tables as defined in the records.
	 *
	 * @param $records
	 */
	private function _createTablesFromRecords($records)
	{
		foreach ($records as $record)
		{
			Blocks::log('Creating table for record:'. get_class($record), \CLogger::LEVEL_INFO);
			$record->createTable();
		}
	}

	/**
	 * Creates the foreign keys as defined in the records.
	 *
	 * @param $records
	 */
	private function _createForeignKeysFromRecords($records)
	{
		foreach ($records as $record)
		{
			Blocks::log('Adding foreign keys for record:'. get_class($record), \CLogger::LEVEL_INFO);
			$record->addForeignKeys();
		}
	}

	/**
	 * Saves some default mail settings for the site.
	 *
	 * @param $email
	 * @param $siteName
	 */
	private function _saveDefaultMailSettings($email, $siteName)
	{
		$success = blx()->systemSettings->saveSettings('email', array(
			'protocol'     => EmailerType::Php,
			'emailAddress' => $email,
			'senderName'   => $siteName
		));

		if (!$success)
			Blocks::log('Could not save default email settings.', \CLogger::LEVEL_ERROR);
	}

	/**
	 * Populates the info table with install and environment information.
	 *
	 * @param $inputs
	 * @throws Exception
	 */
	private function _populateInfoTable($inputs)
	{
		$info = new InfoRecord();
		$info->version = Blocks::getVersion();
		$info->build = Blocks::getBuild();
		$info->releaseDate = Blocks::getReleaseDate();
		$info->siteName = $inputs['siteName'];
		$info->siteUrl = $inputs['siteUrl'];
		$info->language = $inputs['language'];
		/* BLOCKS ONLY */
		$info->licenseKey = $this->_generateLicenseKey();
		/* end BLOCKS ONLY */
		/* BLOCKSPRO ONLY */
		$info->licenseKey = $inputs['licensekey'];
		/* end BLOCKSPRO ONLY */
		$info->on = true;

		$info->save();

		// Something bad happened (probably a validation error)
		if ($info->hasErrors())
		{
			$errors = $info->getErrors();
			$errorMessages = implode('.  ', $errors);
			throw new Exception(Blocks::t('There was a problem saving to the info table: {errorMessages}', array('errorMessages' => $errorMessages)));
		}
	}

	/**
	 * Creates initial database content for the install.
	 *
	 * @return null
	 */
	private function _createDefaultContent()
	{
		/* BLOCKSPRO ONLY */
		Blocks::log('Creating default "Blog" section."', \CLogger::LEVEL_INFO);
		$section = blx()->content->saveSection(array(
			'name'      => Blocks::t('Blog'),
			'handle'    => 'blog',
			'urlFormat' => 'blog/{slug}',
			'hasUrls'   => true,
			'template'  => 'blog/_entry'
		));
		/* end BLOCKSPRO ONLY */

		Blocks::log('Giving "Blog" section a "Body" block.', \CLogger::LEVEL_INFO);
		/* BLOCKS ONLY */
		blx()->content->saveEntryBlock(array(
		/* end BLOCKS ONLY */
		/* BLOCKSPRO ONLY */
		blx()->content->saveEntryBlock($section->id, array(
		/* end BLOCKSPRO ONLY */
			'name'   => Blocks::t('Body'),
			'handle' => 'body',
			'required' => true,
			/* BLOCKSPRO ONLY */
			'translatable' => true,
			/* end BLOCKSPRO ONLY */
			'class' => 'PlainText',
			'blockSettings' => array(
				'hint' => Blocks::t('Enter your blog post’s body…')
			)
		));

		/*// Add a Welcome entry to the Blog
		$entry = blx()->content->createEntry($section->id, null, $user->id, 'Welcome to Blocks Alpha 2');
		blx()->content->saveEntryContent($entry, array(
			'body' => "Hey {$user->username},\n\n" .
			          "Welcome to Blocks Alpha 2!\n\n" .
			          '-Brandon & Brad'
		));*/
	}

	/* BLOCKSPRO ONLY */
	/**
	 * @param $messageKey
	 * @param $subjectKey
	 * @param $bodyKey
	 */
	private function _registerEmailMessage($messageKey, $subjectKey, $bodyKey)
	{
		// Register the email messages
		$message = blx()->email->registerMessage($messageKey);

		if (!$message->hasErrors())
		{
			// Save the message content.
			$content = blx()->email->saveMessageContent($message->id, $subjectKey, $bodyKey);

			// Problem saving content.
			if ($content->hasErrors())
			{
				$errors = $content->getErrors();
				$errorMessages = implode('.  ', $errors);
				Blocks::log('There was a problem saving email message content: '.$errorMessages, \CLogger::LEVEL_WARNING);
			}
		}
		else
		{
			// Problem registering email.
			$errors = $message->getErrors();
			$errorMessages = implode('.  ', $errors);
			Blocks::log('There was a problem registering email with key '.$messageKey.' : '.$errorMessages, \CLogger::LEVEL_WARNING);
		}
	}
	/* end BLOCKSPRO ONLY */
	/* BLOCKS ONLY */

	/**
	 * Generates a license key.
	 *
	 * @return string
	 */
	private function _generateLicenseKey()
	{
		$licenseKey = strtoupper(sprintf('%04x-%04x-%04x-%04x-%04x-%04x',
				mt_rand(0, 0xffff),
				mt_rand(0, 0xffff),
				mt_rand(0, 0xffff),
				mt_rand(0, 0xffff),
				mt_rand(0, 0xffff),
				mt_rand(0, 0xffff)
			));

		return $licenseKey;
	}
	/* end BLOCKS ONLY */
}
