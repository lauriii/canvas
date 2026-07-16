interface CanvasImage {
  src: string;
  alt: string;
  width: number;
  height: number;
}

export default function ImageDisplay({ image }: { image: CanvasImage }) {
  return (
    // This example intentionally demonstrates the native image props shared by every framework.
    // eslint-disable-next-line @next/next/no-img-element
    <img
      className="h-auto w-full rounded-2xl md:col-span-2"
      src={image.src}
      alt={image.alt}
      width={image.width}
      height={image.height}
    />
  );
}
