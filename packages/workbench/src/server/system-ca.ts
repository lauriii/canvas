import tls from 'node:tls';

// Trust certificates from the system CA store, such as those issued by DDEV's CA.
export function trustSystemCertificates(): void {
  tls.setDefaultCACertificates([
    ...tls.getCACertificates('default'),
    ...tls.getCACertificates('system'),
  ]);
}
