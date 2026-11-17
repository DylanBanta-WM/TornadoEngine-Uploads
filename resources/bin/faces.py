# Counts faces in an image with OpenCV's face detectors. Prints the count, or -1 if the image can't be read.
import sys
import cv2

image = cv2.imread(sys.argv[1], cv2.IMREAD_GRAYSCALE)
if image is None:
    print(-1)
    sys.exit(0)
height, width = image.shape
scale = min(1.0, 1600 / max(height, width))
if scale < 1:
    image = cv2.resize(image, None, fx=scale, fy=scale)
image = cv2.equalizeHist(image)
found = 0
for name in ("haarcascade_frontalface_default.xml", "haarcascade_profileface.xml"):
    detector = cv2.CascadeClassifier(sys.argv[2] + "/" + name)
    found += len(detector.detectMultiScale(image, scaleFactor=1.1, minNeighbors=5, minSize=(24, 24)))
    flipped = cv2.flip(image, 1)
    if name == "haarcascade_profileface.xml":
        found += len(detector.detectMultiScale(flipped, scaleFactor=1.1, minNeighbors=5, minSize=(24, 24)))
print(found)
