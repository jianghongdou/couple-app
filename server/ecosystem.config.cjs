// pm2 进程管理配置
//
// 使用：
//   pm2 start ecosystem.config.cjs
//   pm2 logs couple-server
//   pm2 save && pm2 startup     # 开机自启
//
// 注意：本文件使用 .cjs 扩展名，因为 pm2 以 CommonJS 方式加载配置文件，
//       而本项目 package.json 声明了 "type": "module"。
module.exports = {
  apps: [
    {
      name: 'couple-server',
      script: 'src/index.js',
      cwd: __dirname,
      // 单实例：SQLite 为单文件数据库，多实例会争抢写锁
      instances: 1,
      exec_mode: 'fork',
      // 内存超过 500MB 自动重启，防止内存泄漏累积
      max_memory_restart: '500M',
      // 崩溃自动重启，并做退避
      autorestart: true,
      restart_delay: 3000,
      max_restarts: 20,
      // 优雅退出：给在途请求与 WAL 检查点留时间
      kill_timeout: 8000,
      wait_ready: false,
      listen_timeout: 10000,
      // 环境变量
      // 注意：JWT_SECRET 与 ADMIN_PASSWORD 强烈建议写在 server/.env 文件中，
      //       由 dotenv 加载，不要直接写进本文件（避免密钥进入版本库）。
      env: {
        NODE_ENV: 'production',
        PORT: 8787,
        DATA_DIR: './data',
        LOG_LEVEL: 'info',
        TRUST_PROXY: '1',
        TZ: 'Asia/Shanghai',
      },
      // 日志
      output: './logs/out.log',
      error: './logs/error.log',
      merge_logs: true,
      time: true,
    },
  ],
};
