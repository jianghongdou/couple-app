import Foundation
import CryptoKit

/// 本地加密工具：AES-GCM 加密 + PBKDF2-HMAC-SHA256 派生密钥
/// 注意：不使用 CommonCrypto，全部用 CryptoKit 实现，避免链接问题
enum CryptoBox {
    enum CryptoError: LocalizedError {
        case badPassword
        case corruptData

        var errorDescription: String? {
            switch self {
            case .badPassword: return "密码不正确"
            case .corruptData: return "数据已损坏"
            }
        }
    }

    // MARK: - 随机数

    static func randomData(_ count: Int = 16) -> Data {
        var bytes = [UInt8](repeating: 0, count: count)
        let status = SecRandomCopyBytes(kSecRandomDefault, count, &bytes)
        if status != errSecSuccess {
            for i in 0..<count { bytes[i] = UInt8.random(in: 0...255) }
        }
        return Data(bytes)
    }

    // MARK: - PBKDF2-HMAC-SHA256

    /// 纯 CryptoKit 实现的 PBKDF2（RFC 2898）
    static func pbkdf2(password: Data, salt: Data, iterations: Int, keyLength: Int = 32) -> Data {
        let rounds = max(2, iterations)
        let key = SymmetricKey(data: password)
        var derived = Data()
        var blockIndex: UInt32 = 1

        while derived.count < keyLength {
            var blockSalt = salt
            var be = blockIndex.bigEndian
            withUnsafeBytes(of: &be) { raw in
                blockSalt.append(contentsOf: raw)
            }
            var u = Data(HMAC<SHA256>.authenticationCode(for: blockSalt, using: key))
            var accumulator = u

            var round = 2
            while round <= rounds {
                u = Data(HMAC<SHA256>.authenticationCode(for: u, using: key))
                let count = min(accumulator.count, u.count)
                for i in 0..<count {
                    accumulator[i] = accumulator[i] ^ u[i]
                }
                round += 1
            }

            derived.append(accumulator)
            blockIndex += 1
        }

        return Data(derived.prefix(keyLength))
    }

    /// 由密码派生对称密钥
    static func deriveKey(password: String, salt: Data, iterations: Int = 100_000) -> SymmetricKey {
        let data = pbkdf2(
            password: Data(password.utf8),
            salt: salt,
            iterations: iterations,
            keyLength: 32
        )
        return SymmetricKey(data: data)
    }

    // MARK: - AES-GCM

    /// 返回 nonce(12) + 密文 + tag
    static func encrypt(_ plaintext: Data, key: SymmetricKey) throws -> Data {
        let sealed = try AES.GCM.seal(plaintext, using: key)
        guard let combined = sealed.combined else { throw CryptoError.corruptData }
        return combined
    }

    static func decrypt(_ blob: Data, key: SymmetricKey) throws -> Data {
        do {
            let box = try AES.GCM.SealedBox(combined: blob)
            return try AES.GCM.open(box, using: key)
        } catch {
            throw CryptoError.badPassword
        }
    }

    static func encryptString(_ text: String, key: SymmetricKey) throws -> Data {
        try encrypt(Data(text.utf8), key: key)
    }

    static func decryptString(_ blob: Data, key: SymmetricKey) throws -> String {
        let data = try decrypt(blob, key: key)
        guard let text = String(data: data, encoding: .utf8) else { throw CryptoError.corruptData }
        return text
    }

    // MARK: - 摘要

    static func sha256Hex(_ text: String) -> String {
        let digest = SHA256.hash(data: Data(text.utf8))
        return digest.map { String(format: "%02x", $0) }.joined()
    }

    static func sha256Hex(_ data: Data) -> String {
        let digest = SHA256.hash(data: data)
        return digest.map { String(format: "%02x", $0) }.joined()
    }

    /// 情侣共享密钥材料（用于消息摘要校验，不用于存储用户密码）
    static func coupleFingerprint(userA: String, userB: String) -> String {
        let sorted = [userA, userB].sorted().joined(separator: "|")
        return String(sha256Hex(sorted).prefix(12))
    }
}
