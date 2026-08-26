const Document = ({ doc = { src: null, filename: null } }) => {
  const { src, filename } = doc;
  return (
    <>
      {src && (
        <a href={src} download={filename}>
          {filename}
        </a>
      )}
    </>
  );
};

export default Document;
