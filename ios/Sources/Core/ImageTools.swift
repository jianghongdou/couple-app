import Foundation
import UIKit

/// 图片处理：压缩、缩放、转 JPEG
enum ImageTools {
    /// 把图片压到指定字节数以内（表情包上传用）
    static func compress(_ image: UIImage, maxBytes: Int = 400_000, maxDimension: CGFloat = 600) -> Data? {
        let resized = resize(image, maxDimension: maxDimension)
        var quality: CGFloat = 0.9
        var data = resized.jpegData(compressionQuality: quality)
        while let current = data, current.count > maxBytes, quality > 0.2 {
            quality -= 0.1
            data = resized.jpegData(compressionQuality: quality)
        }
        if let current = data, current.count > maxBytes {
            let smaller = resize(resized, maxDimension: maxDimension * 0.6)
            data = smaller.jpegData(compressionQuality: 0.6)
        }
        return data
    }

    /// 头像等小图
    static func compressAvatar(_ image: UIImage) -> Data? {
        compress(image, maxBytes: 120_000, maxDimension: 320)
    }

    static func resize(_ image: UIImage, maxDimension: CGFloat) -> UIImage {
        let width = image.size.width
        let height = image.size.height
        guard width > 0, height > 0 else { return image }
        let maxSide = max(width, height)
        guard maxSide > maxDimension else { return image }
        let scale = maxDimension / maxSide
        let newSize = CGSize(width: width * scale, height: height * scale)
        let renderer = UIGraphicsImageRenderer(size: newSize)
        return renderer.image { _ in
            image.draw(in: CGRect(origin: .zero, size: newSize))
        }
    }

    /// 从相册图片统一转成 jpeg（gif 会退化为静态图）
    static func normalizedJPEG(_ image: UIImage, maxBytes: Int = 400_000) -> Data? {
        compress(image, maxBytes: maxBytes)
    }

    /// 判断是否像图片数据（magic bytes）
    static func detectMime(_ data: Data) -> String? {
        guard data.count > 12 else { return nil }
        let bytes = [UInt8](data.prefix(12))
        if bytes[0] == 0xFF && bytes[1] == 0xD8 && bytes[2] == 0xFF { return "image/jpeg" }
        if bytes[0] == 0x89 && bytes[1] == 0x50 && bytes[2] == 0x4E && bytes[3] == 0x47 { return "image/png" }
        if bytes[0] == 0x47 && bytes[1] == 0x49 && bytes[2] == 0x46 { return "image/gif" }
        if bytes[0] == 0x52 && bytes[1] == 0x49 && bytes[2] == 0x46 && bytes[3] == 0x46,
           bytes[8] == 0x57 && bytes[9] == 0x45 && bytes[10] == 0x42 && bytes[11] == 0x50 {
            return "image/webp"
        }
        return nil
    }
}
