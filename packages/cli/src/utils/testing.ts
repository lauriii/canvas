import { exec, execDrush } from '@drupal-canvas/test-utils';

export type TestSiteInstallData = {
  db_prefix: string;
  site_path: string;
  user_agent: string;
};

export async function installTestSite(): Promise<TestSiteInstallData> {
  const installOutput = await exec(
    `php core/scripts/test-site.php install --no-interaction --install-profile minimal --base-url ${process.env.DRUPAL_TEST_BASE_URL} --db-url ${process.env.DRUPAL_TEST_DB_URL} --json`,
  );
  const installData = JSON.parse(installOutput.toString());

  await exec(
    `DRUPAL_DEV_SITE_PATH=${installData.site_path} php core/scripts/drupal recipe modules/contrib/canvas/tests/fixtures/recipes/test_site_oauth`,
  );

  const drushDrupalSiteInstall = {
    userAgent: installData.user_agent,
    url: process.env.DRUPAL_TEST_BASE_URL || '',
  };
  const testSitePrivatePath = `${installData.site_path}/private`;
  const oauthKeysConfig = JSON.stringify({
    public_key: `${testSitePrivatePath}/public.key`,
    private_key: `${testSitePrivatePath}/private.key`,
  });

  await execDrush(
    `simple-oauth:generate-keys ${testSitePrivatePath}`,
    drushDrupalSiteInstall,
  );

  await execDrush(
    `config:set --input-format=yaml simple_oauth.settings ? ${JSON.stringify(oauthKeysConfig)} --yes`,
    drushDrupalSiteInstall,
  );

  await execDrush(`config:get simple_oauth.settings`, drushDrupalSiteInstall);

  return installData;
}

export async function tearDownTestSite(db_prefix: string): Promise<void> {
  await exec(
    `php core/scripts/test-site.php tear-down --no-interaction --db-url ${process.env.DRUPAL_TEST_DB_URL} ${db_prefix}`,
  );
}
